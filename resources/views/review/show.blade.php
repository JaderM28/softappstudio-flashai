@php
    use App\Enums\CardType;
    use App\Enums\ReviewGrade;

    // Presentation only, so it stays here rather than on the enum.
    $gradeClasses = fn (ReviewGrade $grade) => match ($grade) {
        ReviewGrade::Again => 'bg-red-600 hover:bg-red-700',
        ReviewGrade::Hard => 'bg-amber-600 hover:bg-amber-700',
        ReviewGrade::Good => 'bg-emerald-600 hover:bg-emerald-700',
        ReviewGrade::Easy => 'bg-sky-600 hover:bg-sky-700',
    };
@endphp

<x-app-layout>
    <div class="py-4 sm:py-10"
         x-data="reviewSession()"
         @keydown.window.space.prevent="revealed ? null : reveal()"
         @keydown.window.1="grade(1)"
         @keydown.window.2="grade(2)"
         @keydown.window.3="grade(3)"
         @keydown.window.4="grade(4)">

        <div class="max-w-xl mx-auto px-4 sm:px-6 space-y-4">

            <x-flash-status />

            {{-- What is left, so the session has a visible end. --}}
            <div class="flex items-center justify-between gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-3">
                    <span>{{ $deck->name }}</span>
                    @if ($canUndo)
                        <form method="POST" action="{{ route('review.undo') }}">
                            @csrf
                            <button type="submit" class="hover:text-gray-900 dark:hover:text-gray-100 underline">
                                {{ __('Undo') }}
                            </button>
                        </form>
                    @endif
                </span>
                <span class="flex gap-3 tabular-nums">
                    @foreach ($counts as $queue => $count)
                        @if ($count > 0)
                            <span>{{ \App\Enums\CardQueue::from($queue)->label() }} {{ $count }}</span>
                        @endif
                    @endforeach
                </span>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg overflow-hidden">

                @if ($note->imageSrc())
                    <img src="{{ $note->imageSrc() }}" alt=""
                         class="w-full h-48 sm:h-64 object-cover bg-gray-100 dark:bg-gray-900">
                @endif

                <div class="p-6 sm:p-8 text-center space-y-5">

                    @if ($card->type === CardType::Listening)
                        <p class="text-sm uppercase tracking-widest text-gray-500 dark:text-gray-400">
                            {{ __('What did you hear?') }}
                        </p>
                    @endif

                    @if ($note->audioSentenceSrc())
                        <audio x-ref="audio" src="{{ $note->audioSentenceSrc() }}" preload="auto"></audio>
                        <button type="button" @click="$refs.audio.play()"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm hover:bg-gray-200 dark:hover:bg-gray-600 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500">
                            <span aria-hidden="true">▶</span> {{ __('Play') }}
                        </button>
                    @endif

                    {{-- Front.

                         The revealed sentence is always the tappable one, and the
                         hidden one never is: tapping a word while the blank is
                         still on screen would read the answer out loud. --}}
                    @if ($card->type === CardType::Cloze)
                        <p class="text-2xl sm:text-3xl leading-snug text-gray-900 dark:text-gray-100">
                            <span x-show="!revealed">{{ $note->clozePrompt() }}</span>
                            <span x-show="revealed" x-cloak>
                                <x-spoken-sentence :sentence="$note->sentence"
                                                   :language="$deck->target_language"
                                                   :timings="$note->word_timings"
                                                   :audio-url="$note->audioSentenceSrc()" />
                            </span>
                        </p>
                    @elseif ($card->type === CardType::Listening)
                        <p class="text-2xl sm:text-3xl leading-snug text-gray-900 dark:text-gray-100"
                           x-show="revealed" x-cloak>
                            <x-spoken-sentence :sentence="$note->sentence"
                                               :language="$deck->target_language"
                                               :timings="$note->word_timings"
                                               :audio-url="$note->audioSentenceSrc()" />
                        </p>
                    @else
                        <p class="text-lg text-gray-600 dark:text-gray-300">{{ $note->meaning }}</p>
                        <p class="text-2xl sm:text-3xl leading-snug text-gray-900 dark:text-gray-100"
                           x-show="revealed" x-cloak>
                            <x-spoken-sentence :sentence="$note->sentence"
                                               :language="$deck->target_language"
                                               :timings="$note->word_timings"
                                               :audio-url="$note->audioSentenceSrc()" />
                        </p>
                    @endif

                    {{-- Back --}}
                    <div x-show="revealed" x-cloak class="space-y-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                        <p class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                            {{ $note->target }}
                        </p>

                        @if ($note->pronunciation)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $note->pronunciation }}</p>
                        @endif

                        @if ($note->meaning && $card->type !== CardType::Production)
                            <p class="text-gray-600 dark:text-gray-300">{{ $note->meaning }}</p>
                        @endif

                        @if ($deck->show_translation && $note->translation)
                            <p class="text-gray-500 dark:text-gray-400 italic">{{ $note->translation }}</p>
                        @endif

                        @if ($note->source)
                            <p class="text-xs text-gray-400 dark:text-gray-500">{{ $note->source }}</p>
                        @endif
                    </div>

                </div>
            </div>

            {{-- Reveal --}}
            <button type="button" x-show="!revealed" @click="reveal()"
                    class="w-full py-4 rounded-md bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800 font-semibold text-lg hover:bg-gray-700 dark:hover:bg-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 transition">
                {{ __('Show answer') }}
            </button>

            {{-- Grade --}}
            <div x-show="revealed" x-cloak class="grid grid-cols-4 gap-2">
                @foreach (ReviewGrade::cases() as $grade)
                    <form method="POST" action="{{ route('review.grade', $card) }}"
                          x-ref="grade{{ $grade->value }}"
                          @submit="stampDuration($el)">
                        @csrf
                        <input type="hidden" name="grade" value="{{ $grade->value }}">
                        <input type="hidden" name="duration_ms" value="">
                        <button type="submit"
                                class="w-full py-4 rounded-md text-white font-semibold {{ $gradeClasses($grade) }} focus:outline-hidden focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500 dark:focus-visible:ring-offset-gray-900 transition">
                            <span class="block text-sm sm:text-base">{{ $grade->label() }}</span>
                            {{-- What this button would actually do. Hard and Good are a
                                 choice between two futures, and choosing blind is how a
                                 card ends up months out from a mis-tap. --}}
                            <span class="block text-[11px] opacity-80 tabular-nums">
                                {{ $previews[$grade->value] ?? $grade->value }}
                            </span>
                        </button>
                    </form>
                @endforeach
            </div>

            <p class="text-center text-xs text-gray-400 dark:text-gray-500" x-show="revealed" x-cloak>
                {{ __('Tap any word to hear it · keys 1–4 to grade, space to reveal') }}
            </p>

        </div>
    </div>

    @push('scripts')
        <script>
            function reviewSession() {
                return {
                    revealed: false,
                    shownAt: Date.now(),

                    reveal() {
                        this.revealed = true;
                    },

                    // Time from the card appearing to the grade being given.
                    // Stamped on submit rather than bound, because Date.now()
                    // is not reactive and a binding would freeze at render.
                    elapsed() {
                        return Date.now() - this.shownAt;
                    },

                    stampDuration(form) {
                        form.querySelector('[name="duration_ms"]').value = this.elapsed();
                    },

                    grade(value) {
                        if (! this.revealed) {
                            return;
                        }

                        this.$refs['grade' + value]?.requestSubmit();
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
