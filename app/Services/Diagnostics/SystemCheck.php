<?php

namespace App\Services\Diagnostics;

use App\Contracts\SentenceGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Enums\ProbeStatus;
use App\Models\Deck;
use App\Services\Media\CloudflareTextToSpeech;
use App\Services\Media\GeminiTextToSpeech;
use App\Services\Media\OpenverseImageProvider;
use App\Services\Media\PixabayImageProvider;
use App\Services\Media\UnsplashImageProvider;
use App\Support\FoundImage;
use App\Support\GenerationRequest;
use App\Support\ProbeResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Calls the real services and reports what happened.
 *
 * This exists because the alternative was believing the code. Every external
 * client in this project was written against documentation, faked in tests with
 * the same shape the client expected, and never once called — which is how the
 * speech synthesiser shipped reading the audio out of a field the live API does
 * not use, and produced nothing at all for weeks while the suite stayed green.
 *
 * Two rules give it its shape:
 *
 *  - **Each provider is probed on its own, never through the fallback chain.**
 *    Through the chain, a working Pixabay hides a broken Unsplash key, and the
 *    answer to "why are there no pictures" stays hidden behind the one that
 *    still works.
 *  - **No probe may throw.** This is what the screen calls to find out why
 *    things are broken; it cannot be the next thing that breaks.
 */
class SystemCheck
{
    /** Where probe artefacts go, so they can be looked at and listened to. */
    public const DIRECTORY = 'diagnostics';

    /** @var array<int, string> */
    public const GROUPS = ['image', 'audio', 'sentence', 'infra'];

    /**
     * @param  array<int, string>  $groups
     * @return Collection<int, ProbeResult>
     */
    public function run(string $phrase, string $language = 'en', array $groups = self::GROUPS): Collection
    {
        $this->sweepOldArtefacts();

        $results = collect();

        if (in_array('image', $groups, true)) {
            $results = $results->merge($this->imageProbes($phrase));
        }

        if (in_array('audio', $groups, true)) {
            $results = $results->merge($this->audioProbes($phrase, $language));
        }

        if (in_array('sentence', $groups, true)) {
            $results->push($this->sentenceProbe($phrase, $language));
        }

        if (in_array('infra', $groups, true)) {
            $results = $results->merge($this->infrastructureProbes());
        }

        return $results;
    }

    /**
     * Facts about the installation that need no call to establish. Shown beside
     * the probes because half of a red probe's explanation lives here.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            'Environment' => app()->environment(),
            'Media disk' => (string) config('flashai.media.disk'),
            'Queue connection' => (string) config('queue.default'),
            'Audio compression' => config('flashai.media.audio.compress') ? 'on' : 'off',
            'Gemini key' => $this->maskedKey(config('services.gemini.key')),
            'Cloudflare token' => $this->maskedKey(config('services.cloudflare.token')),
            'Cloudflare voice' => (string) config('services.cloudflare.tts.voice'),
            'Gemini model' => (string) config('services.gemini.model'),
            'Gemini TTS model' => (string) config('services.gemini.tts.model'),
            'Gemini TTS voice' => (string) config('services.gemini.tts.voice'),
            'Pixabay key' => $this->maskedKey(config('services.pixabay.key')),
            'Unsplash key' => $this->maskedKey(config('services.unsplash.key')),
            'Openverse' => 'no key needed',
        ];
    }

    /**
     * One probe per provider, plus one for the download-and-store step that
     * follows a search and fails for entirely different reasons.
     *
     * @return Collection<int, ProbeResult>
     */
    private function imageProbes(string $phrase): Collection
    {
        $providers = [
            'Pixabay' => new PixabayImageProvider,
            'Openverse' => new OpenverseImageProvider,
            'Unsplash' => new UnsplashImageProvider,
        ];

        $results = collect();
        $downloadable = null;

        foreach ($providers as $name => $provider) {
            $results->push($this->timed($name, 'image', function () use ($provider, $phrase, &$downloadable) {
                $found = $provider->search($phrase);
                $downloadable ??= $found;

                return [
                    'detail' => $found->url,
                    'payload' => [
                        'url' => $found->url,
                        'photographer' => $found->photographer,
                        'description' => $found->description,
                    ],
                ];
            }));
        }

        $results->push($downloadable === null
            ? ProbeResult::skipped('Image download', 'image', 'No provider returned a picture to download.')
            : $this->downloadProbe($downloadable));

        return $results;
    }

    /**
     * Searching is not the same as having the file. This is the step that
     * catches a provider whose CDN refuses the request our own client makes.
     */
    private function downloadProbe(FoundImage $found): ProbeResult
    {
        return $this->timed('Image download', 'image', function () use ($found) {
            $response = Http::timeout(20)->get($found->url);

            if ($response->failed()) {
                throw new \RuntimeException("The picture host answered HTTP {$response->status()}.");
            }

            $bytes = $response->body();
            $path = self::DIRECTORY.'/'.Str::uuid().'.jpg';
            $this->disk()->put($path, $bytes);

            return [
                'detail' => number_format(strlen($bytes)).' bytes stored at '.$path,
                'payload' => [
                    'path' => $path,
                    'url' => $this->disk()->url($path),
                    'bytes' => strlen($bytes),
                ],
            ];
        });
    }

    /**
     * @return Collection<int, ProbeResult>
     */
    private function audioProbes(string $phrase, string $language): Collection
    {
        // Each voice on its own, never through the chain — the same reason the
        // image providers are probed separately. Through the fallback, a
        // working Cloudflare hides an expired Gemini key, and "why did the
        // voice change?" has no answer on this page.
        $synthesizers = [
            'Cloudflare speech' => new CloudflareTextToSpeech,
            'Gemini speech' => new GeminiTextToSpeech,
            'Speech (as configured)' => app(SpeechSynthesizer::class),
        ];

        return collect($synthesizers)->map(
            fn (SpeechSynthesizer $speech, string $name) => $this->timed(
                $name,
                'audio',
                function () use ($speech, $phrase, $language, $name) {
                    $audio = $speech->synthesize($phrase, $language);

                    if ($audio->isEmpty()) {
                        throw new \RuntimeException('The clip came back empty.');
                    }

                    $path = self::DIRECTORY.'/'.Str::uuid().'.'.$audio->extension;
                    $this->disk()->put($path, $audio->bytes);

                    return [
                        'detail' => sprintf(
                            '%s bytes of %s stored at %s',
                            number_format(strlen($audio->bytes)),
                            mb_strtoupper($audio->extension),
                            $path,
                        ),
                        'payload' => [
                            'path' => $path,
                            'url' => $this->disk()->url($path),
                            'bytes' => strlen($audio->bytes),
                            'extension' => $audio->extension,
                            'synthesizer' => $name,
                        ],
                    ];
                },
            )
        )->values();
    }

    private function sentenceProbe(string $phrase, string $language): ProbeResult
    {
        return $this->timed('Gemini sentences', 'sentence', function () use ($phrase, $language) {
            $deck = new Deck([
                'target_language' => $language,
                'native_language' => 'es',
            ]);

            $note = app(SentenceGenerator::class)->generate(GenerationRequest::forDeck($phrase, $deck));

            return [
                'detail' => "\"{$note->sentence}\" · target: {$note->target} · scene: {$note->imageQuery}",
                'payload' => [
                    'sentence' => $note->sentence,
                    'target' => $note->target,
                    'image_query' => $note->imageQuery,
                ],
            ];
        });
    }

    /**
     * @return Collection<int, ProbeResult>
     */
    private function infrastructureProbes(): Collection
    {
        return collect([
            $this->storageProbe(),
            $this->ffmpegProbe(),
            $this->queueProbe(),
        ]);
    }

    /**
     * The probe worth having most, and the one nothing else covers.
     *
     * Writing to the disk is only half the job: in production the disk is
     * Supabase over S3, where the upload endpoint and the public URL are
     * different hosts, and a wrong AWS_URL stores every picture perfectly and
     * serves none of them. So this writes, reads the file back over HTTP, and
     * then cleans up.
     */
    private function storageProbe(): ProbeResult
    {
        return $this->timed('Storage round trip', 'infra', function () {
            $path = self::DIRECTORY.'/'.Str::uuid().'.txt';
            $body = 'flashai storage probe';

            $this->disk()->put($path, $body);

            try {
                if ($this->disk()->get($path) !== $body) {
                    throw new \RuntimeException('The file read back different from what was written.');
                }

                $url = $this->absolute($this->disk()->url($path));

                $response = Http::withoutVerifying()->timeout(15)->get($url);

                if ($response->failed()) {
                    throw new \RuntimeException(
                        "Stored fine, but serving it over HTTP answered {$response->status()}. ".
                        "The file is at {$path} and its public URL is {$url}. ".
                        'On the s3 disk this usually means AWS_URL points somewhere the bucket does not serve.'
                    );
                }

                return ['detail' => "Written, read back and served from {$url}"];
            } finally {
                $this->disk()->delete($path);
            }
        });
    }

    /**
     * Without ffmpeg the audio is stored as WAV — about ten times the size of
     * the MP3 it should be. It degrades rather than fails, which is exactly why
     * it needs saying out loud somewhere.
     */
    private function ffmpegProbe(): ProbeResult
    {
        $binary = (string) config('flashai.media.audio.ffmpeg');

        if ($binary === '') {
            return ProbeResult::skipped('ffmpeg', 'infra', 'No path configured, so audio stays uncompressed.');
        }

        return $this->timed('ffmpeg', 'infra', function () use ($binary) {
            $result = Process::timeout(15)->run([$binary, '-version']);

            if (! $result->successful()) {
                throw new \RuntimeException(
                    "Running {$binary} failed: ".trim($result->errorOutput() ?: $result->output()).
                    ' Audio will be stored as WAV, roughly ten times the size.'
                );
            }

            return ['detail' => trim(strtok($result->output(), "\n") ?: 'present')];
        });
    }

    /**
     * The silent failure this design is most prone to: every media job is
     * queued, so a worker that is not running looks exactly like a service that
     * is slow. Nothing errors, nothing logs, and notes sit on Pending forever.
     */
    private function queueProbe(): ProbeResult
    {
        return $this->timed('Queue worker', 'infra', function () {
            if (config('queue.default') !== 'database') {
                return ['detail' => 'Queue connection is "'.config('queue.default').'", so there is no worker to check.'];
            }

            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');

            $waitingMinutes = $oldest === null ? 0 : (int) ((time() - (int) $oldest) / 60);

            if ($waitingMinutes > 10) {
                throw new \RuntimeException(
                    "The oldest queued job has been waiting {$waitingMinutes} minutes. ".
                    'That almost always means no worker is running.'
                );
            }

            return [
                'detail' => "{$pending} pending, {$failed} failed",
                'payload' => ['pending' => $pending, 'failed' => $failed],
            ];
        });
    }

    /**
     * Run a probe, time it, and turn anything it throws into a result.
     *
     * @param  callable(): (array{detail?: string, payload?: array<string, mixed>}|null)  $probe
     */
    private function timed(string $service, string $group, callable $probe): ProbeResult
    {
        $started = microtime(true);

        try {
            $outcome = $probe() ?? [];

            return ProbeResult::ok(
                $service,
                $group,
                $this->elapsed($started),
                $outcome['detail'] ?? '',
                $outcome['payload'] ?? [],
            );
        } catch (Throwable $e) {
            return ProbeResult::failed($service, $group, $this->elapsed($started), $e);
        }
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('flashai.media.disk'));
    }

    /**
     * The public disk hands back a root-relative path, which no HTTP client can
     * fetch on its own.
     */
    private function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    private function maskedKey(?string $key): string
    {
        if (blank($key)) {
            return 'not set';
        }

        return 'set, ending '.mb_substr($key, -4);
    }

    /**
     * Probe artefacts are kept so they can be looked at and played, which means
     * something has to throw them away. An hour is long enough to read the page
     * and short enough that a metered bucket never notices.
     */
    private function sweepOldArtefacts(): void
    {
        try {
            $disk = $this->disk();

            foreach ($disk->files(self::DIRECTORY) as $path) {
                if ($disk->lastModified($path) < now()->subHour()->getTimestamp()) {
                    $disk->delete($path);
                }
            }
        } catch (Throwable) {
            // Housekeeping must never be the reason diagnostics fail to run.
        }
    }
}
