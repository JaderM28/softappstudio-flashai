@props([
    'sentence',
    'language' => 'en',
    // When the note knows where each word falls in its clip, tapping plays that
    // stretch of the sentence — instant, free, and the word as it is actually
    // said rather than in isolation. Without them, the word is synthesised on
    // its own, which always works.
    'timings' => null,
    'audioUrl' => null,
])

{{--
    A sentence whose words can each be tapped to hear them.

    Only ever rendered after the answer is revealed. Tapping a word while the
    blank is still on screen would read the answer out loud, which is the one
    thing a flashcard must not do.
--}}
<span {{ $attributes }}
      x-data="spokenSentence({
          endpoint: @js(route('speak')),
          language: @js($language),
          timings: @js(collect($timings ?? [])->keyBy('word')),
          sentenceAudio: @js($audioUrl),
      })">
    @foreach (preg_split('/(\s+)/u', $sentence, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $chunk)
        @if (trim($chunk) === '')
            {{ $chunk }}
        @else
            <button type="button"
                    @click="speak($event.currentTarget, @js($chunk))"
                    class="rounded px-0.5 -mx-0.5 transition
                           hover:bg-indigo-100 dark:hover:bg-indigo-900/40
                           focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500
                           disabled:opacity-50"
                    :class="playing === @js($chunk) ? 'bg-indigo-100 dark:bg-indigo-900/40' : ''">{{ $chunk }}</button>
        @endif
    @endforeach

    <span x-show="error" x-cloak
          class="block mt-2 text-xs text-gray-400 dark:text-gray-500"
          x-text="error"></span>
</span>

@once
    @push('scripts')
        <script>
            function spokenSentence({ endpoint, language, timings, sentenceAudio }) {
                return {
                    playing: null,
                    error: null,
                    slicer: null,
                    sliceTimer: null,

                    // Clips already fetched this session. The server stores them
                    // too — this only saves the round trip.
                    heard: {},

                    async speak(button, word) {
                        this.error = null;
                        this.playing = word;

                        try {
                            // The stretch of the sentence clip where this word
                            // is, when the note knows it. Nothing to fetch and
                            // nothing to wait for.
                            const timing = timings[word];

                            if (timing && sentenceAudio) {
                                return await this.playSlice(word, timing);
                            }

                            if (! this.heard[word]) {
                                const response = await fetch(endpoint, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                    },
                                    body: JSON.stringify({ word, language }),
                                });

                                const data = await response.json();

                                if (! response.ok) {
                                    throw new Error(data.error ?? 'That word could not be read aloud.');
                                }

                                this.heard[word] = new Audio(data.url);
                            }

                            const audio = this.heard[word];

                            audio.currentTime = 0;
                            await audio.play();

                            audio.onended = () => {
                                if (this.playing === word) {
                                    this.playing = null;
                                }
                            };
                        } catch (e) {
                            this.error = e.message;
                            this.playing = null;
                        }
                    },

                    /**
                     * Play one stretch of the sentence clip.
                     *
                     * One <audio> reused for every word: creating one per tap
                     * leaves a pile of them decoding the same file, and the
                     * browser stops honouring play() somewhere around the
                     * twentieth.
                     */
                    async playSlice(word, timing) {
                        if (! this.slicer) {
                            this.slicer = new Audio(sentenceAudio);
                        }

                        const audio = this.slicer;

                        clearTimeout(this.sliceTimer);

                        audio.currentTime = timing.start;
                        await audio.play();

                        // A hair past the end, so the last consonant is not
                        // clipped off — which is exactly the part of a word a
                        // learner needs to hear.
                        this.sliceTimer = setTimeout(() => {
                            audio.pause();

                            if (this.playing === word) {
                                this.playing = null;
                            }
                        }, Math.max(120, (timing.end - timing.start) * 1000 + 60));
                    },
                };
            }
        </script>
    @endpush
@endonce
