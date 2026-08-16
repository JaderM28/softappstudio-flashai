<?php

namespace App\Http\Controllers;

use App\Actions\QueueNoteMedia;
use App\Actions\StoreNoteImage;
use App\Actions\SyncNoteCards;
use App\Enums\AssetStatus;
use App\Exceptions\MediaFetchFailed;
use App\Models\Note;
use App\Services\Media\ImageCandidates;
use App\Services\Media\ImageTranscoder;
use App\Support\FoundImage;
use App\Support\ImageQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Where a saved sentence becomes a card.
 *
 * The step this app was missing. Sentences used to become cards the instant
 * they were typed, and their picture and clip turned up later or not at all —
 * so the thing the whole design rests on, that the image carries the meaning
 * and the audio carries the sound, was the one part nobody ever checked.
 *
 * Here the sentence is already saved and safe. What happens on this screen is
 * looking at the picture, listening to the clip, changing either, and only then
 * pressing the button that creates the cards.
 */
class NoteCompositionController extends Controller
{
    public function __construct(
        private readonly ImageCandidates $candidates,
        private readonly StoreNoteImage $storeImage,
        private readonly SyncNoteCards $syncCards,
        private readonly QueueNoteMedia $queueMedia,
    ) {}

    public function show(Request $request, Note $note): View
    {
        $this->authorize('update', $note);

        return view('notes.compose', [
            'note' => $note,
            'query' => ImageQuery::forNote($note->image_query, $note->sentence, $note->target),
            // The same shape the polling endpoint returns, so the page has one
            // idea of its own state rather than two that can disagree.
            'state' => $this->stateOf($note),
        ]);
    }

    /**
     * Polled by the page while the jobs run.
     *
     * Two seconds of a query that reads a handful of columns and a cache entry,
     * against websockets and the infrastructure they need for a screen one
     * person looks at for thirty seconds. It stops as soon as the answer is
     * final.
     */
    public function status(Request $request, Note $note): JsonResponse
    {
        $this->authorize('update', $note);

        return response()->json($this->stateOf($note));
    }

    /**
     * Everything the screen shows, in one place.
     *
     * @return array<string, mixed>
     */
    private function stateOf(Note $note): array
    {
        return [
            'image_status' => $note->image_status->value,
            'audio_status' => $note->audio_status->value,
            'image_url' => $note->imageSrc(),
            'audio_sentence_url' => $note->audioSentenceSrc(),
            'audio_target_url' => $note->audioTargetSrc(),
            'candidates' => $this->candidates->for($note)
                ->map(fn (FoundImage $image, int $index) => [
                    'index' => $index,
                    'thumbnail' => $image->thumbnail(),
                    'credit' => trim("{$image->photographer} · {$image->source}"),
                    'chosen' => $image->url === $note->image_url,
                ])
                ->values()
                ->all(),
            'errors' => (object) ($note->generation_errors ?? []),
            'missing' => $note->missingForCards(),
            'complete' => $note->isComplete(),
            // Nothing else is going to change on its own, so the page can stop
            // asking.
            'settled' => $this->hasSettled($note),
        ];
    }

    /**
     * Pick one of the offered pictures.
     *
     * An index, never a URL. A URL posted from a browser is a URL the server
     * can be told to fetch, and the candidates are held server-side precisely
     * so that this request cannot name an arbitrary one.
     */
    public function chooseImage(Request $request, Note $note): RedirectResponse
    {
        $this->authorize('update', $note);

        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0', 'max:'.(ImageCandidates::LIMIT - 1)],
        ]);

        $chosen = $this->candidates->at($note, $validated['index']);

        if ($chosen === null) {
            return back()->withErrors(['index' => __('That picture is no longer on offer. Search again.')]);
        }

        try {
            $this->storeImage->handle($note, $chosen);
        } catch (MediaFetchFailed $e) {
            return back()->withErrors(['index' => $e->userMessage()]);
        }

        return back()->with('status', __('Picture chosen.'));
    }

    /**
     * Rewrite the scene and look again.
     *
     * The scene is what gets searched, not the sentence: stock libraries match
     * tags, and "She borrowed my umbrella yesterday" leads with a Christmas
     * dinner table. Two or three concrete nouns is the shape that works.
     */
    public function searchImages(Request $request, Note $note): RedirectResponse
    {
        $this->authorize('update', $note);

        $validated = $request->validate([
            'image_query' => ['required', 'string', 'max:120'],
        ]);

        $note->update(['image_query' => $validated['image_query']]);

        try {
            $found = $this->candidates->refresh($note, $validated['image_query']);
        } catch (MediaFetchFailed $e) {
            return back()->withErrors(['image_query' => $e->userMessage()]);
        }

        return back()->with('status', trans_choice(
            '{0} Nothing matched that.|{1} One picture found.|[2,*] :count pictures found.',
            $found->count(),
            ['count' => $found->count()],
        ));
    }

    /**
     * Use a picture of your own.
     *
     * The last resort that makes the tray a tray and not a dead end: some
     * concepts are in no stock library at any price, and a sentence should
     * never be stuck because of that.
     */
    public function uploadImage(Request $request, Note $note, ImageTranscoder $transcoder): RedirectResponse
    {
        $this->authorize('update', $note);

        $validated = $request->validate([
            'image' => [
                'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096',
                'dimensions:min_width=200,min_height=200',
            ],
        ]);

        $file = $validated['image'];

        [$bytes, $extension] = $transcoder->toCardSize(
            (string) file_get_contents($file->getRealPath()),
            $file->getClientOriginalExtension() ?: 'jpg',
        );

        $path = "notes/images/{$note->id}.{$extension}";

        Storage::disk(config('flashai.media.disk'))->put($path, $bytes);

        $note->update([
            'image_status' => AssetStatus::Ready,
            'image_path' => $path,
            // No remote original and nobody else to credit.
            'image_url' => null,
            'image_attribution' => ['photographer' => __('You'), 'source' => 'upload'],
            'generation_errors' => collect($note->generation_errors ?? [])->except('image')->all() ?: null,
        ]);

        return back()->with('status', __('Your picture is in place.'));
    }

    /**
     * Ask for the failed half again — after fixing a key, or when a provider
     * was simply having a bad afternoon.
     */
    public function retryMedia(Request $request, Note $note): RedirectResponse
    {
        $this->authorize('update', $note);

        $this->queueMedia->handle(
            $note,
            image: $note->image_status !== AssetStatus::Ready,
            audio: $note->audio_status !== AssetStatus::Ready,
        );

        return back()->with('status', __('Trying again.'));
    }

    /**
     * The button. Everything above this exists to make pressing it a decision
     * rather than a formality.
     */
    public function complete(Request $request, Note $note): RedirectResponse
    {
        $this->authorize('update', $note);

        if (! $note->isComplete()) {
            return back()->withErrors([
                'complete' => __('This sentence still needs its picture and its audio.'),
            ]);
        }

        $created = $this->syncCards->handle($note);

        // The grid has done its job, and holding six URLs per note in the cache
        // for a day after that is just rent.
        $this->candidates->forget($note);

        return redirect()
            ->route('notes.create')
            ->with('status', trans_choice(
                '{0} That sentence already had its cards.|{1} Card created.|[2,*] :count cards created.',
                $created->count(),
                ['count' => $created->count()],
            ));
    }

    /**
     * Whether anything is still expected to change by itself.
     */
    private function hasSettled(Note $note): bool
    {
        return $note->image_status->isSettled() && $note->audio_status->isSettled();
    }
}
