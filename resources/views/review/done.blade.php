@php
    // Three endings that used to be one screen saying "Nothing left due".
    //
    // They mean entirely different things and want entirely different answers:
    // add a sentence, wait nine minutes, or come back tomorrow. Telling them
    // apart is most of the difference between an app that feels finished and
    // one that feels broken.
    $ending = match (true) {
        $noteCount === 0 => 'empty',
        $unfinished > 0 && $reviewedToday === 0 && $nextDueAt === null => 'unfinished',
        $nextDueLabel !== null && $reloadInSeconds !== null => 'waiting',
        $heldBack > 0 => 'capped',
        default => 'done',
    };
@endphp

<x-app-layout>
    <div class="py-12" @if ($reloadInSeconds !== null && $ending === 'waiting')
        x-data="{}"
        x-init="setTimeout(() => window.location.reload(), {{ $reloadInSeconds * 1000 }})"
    @endif>
        <div class="max-w-xl mx-auto px-4 sm:px-6 text-center space-y-6">

            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-8 sm:p-10">

                <p class="text-5xl" aria-hidden="true">
                    {{ ['empty' => '·', 'unfinished' => '⋯', 'waiting' => '⏳', 'capped' => '✓', 'done' => '✓'][$ending] }}
                </p>

                <h1 class="mt-4 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                    @switch($ending)
                        @case('empty')
                            {{ __('Nothing to study yet') }}
                            @break
                        @case('unfinished')
                            {{ __('Nothing ready yet') }}
                            @break
                        @case('waiting')
                            {{ __('Back in :interval', ['interval' => $nextDueLabel]) }}
                            @break
                        @case('capped')
                            {{ __("That's today's session") }}
                            @break
                        @default
                            {{ __('Nothing left due') }}
                    @endswitch
                </h1>

                <p class="mt-2 text-gray-500 dark:text-gray-400">
                    @switch($ending)
                        @case('empty')
                            {{ __('Add your first sentence and it will be waiting here.') }}
                            @break

                        @case('unfinished')
                            {{-- The state that is otherwise invisible: sentences exist,
                                 but none has both its picture and its audio yet. --}}
                            {{ trans_choice(
                                '{1} One sentence is still waiting for its picture or its audio.|[2,*] :count sentences are still waiting for their picture or audio.',
                                $unfinished,
                                ['count' => $unfinished],
                            ) }}
                            @break

                        @case('waiting')
                            {{-- Anki's learning steps are minutes long. Without saying
                                 so, answering "Hard" looks like the session ending. --}}
                            {{ __('A card you are learning comes back then. This page will refresh itself.') }}
                            @break

                        @case('capped')
                            {{ trans_choice(
                                '{1} :count card is waiting behind today\'s limit.|[2,*] :count cards are waiting behind today\'s limit.',
                                $heldBack,
                                ['count' => $heldBack],
                            ) }}
                            @break

                        @default
                            @if ($reviewedToday > 0)
                                {{ trans_choice('{1} :count card reviewed today.|[2,*] :count cards reviewed today.', $reviewedToday, ['count' => $reviewedToday]) }}
                            @else
                                {{ __('Come back when something falls due.') }}
                            @endif
                    @endswitch
                </p>

                {{-- The screen knows when the next card is due; saying so costs a
                     line and turns "nothing left" into an actual answer. --}}
                @if ($nextDueLabel !== null && in_array($ending, ['done', 'capped'], true))
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Next card in :interval.', ['interval' => $nextDueLabel]) }}
                    </p>
                @endif

                @if ($reviewedToday > 0 && $ending !== 'done')
                    <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">
                        {{ trans_choice('{1} :count card reviewed today.|[2,*] :count cards reviewed today.', $reviewedToday, ['count' => $reviewedToday]) }}
                    </p>
                @endif

                @if ($ending === 'done' || $ending === 'capped')
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        @if ($newRemaining > 0)
                            {{ trans_choice(
                                '{1} Room for :count more new sentence today.|[2,*] Room for :count more new sentences today.',
                                $newRemaining,
                                ['count' => $newRemaining],
                            ) }}
                        @else
                            {{ __("Today's new sentences are all started. Adding more is fine — they will begin tomorrow.") }}
                        @endif
                    </p>
                @endif

                @if ($canUndo)
                    <form method="POST" action="{{ route('review.undo') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 underline hover:text-gray-900 dark:hover:text-gray-100">
                            {{ __('Undo last answer') }}
                        </button>
                    </form>
                @endif

                <div class="mt-6 flex flex-col sm:flex-row gap-3 justify-center">
                    @if ($ending === 'unfinished')
                        <a href="{{ route('notes.index', ['status' => 'incomplete']) }}"
                           class="inline-flex justify-center px-5 py-3 rounded-md bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800 font-semibold hover:bg-gray-700 dark:hover:bg-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 transition">
                            {{ __('Finish them') }}
                        </a>
                    @else
                        <a href="{{ route('notes.create') }}"
                           class="inline-flex justify-center px-5 py-3 rounded-md bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800 font-semibold hover:bg-gray-700 dark:hover:bg-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 transition">
                            {{ __('Add a sentence') }}
                        </a>
                    @endif

                    <a href="{{ route('dashboard') }}"
                       class="inline-flex justify-center px-5 py-3 rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 transition">
                        {{ __('Back to today') }}
                    </a>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
