<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Finish this card') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-12"
         x-data='compose({
             statusUrl: @json(route("notes.compose.status", $note)),
             state: @json($state)
         })'
         x-init="poll()">

        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-flash-status />

            @if ($errors->any())
                <div class="rounded-md border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-800 dark:text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            {{-- The sentence itself. Saved already: nothing on this screen can lose it. --}}
            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6">
                <p class="text-xl leading-snug text-gray-900 dark:text-gray-100">{{ $note->sentence }}</p>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $note->target }}</span>
                    @if ($note->meaning) — {{ $note->meaning }} @endif
                </p>
                <a href="{{ route('notes.edit', $note) }}"
                   class="mt-3 inline-block text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                    {{ __('Edit the wording') }}
                </a>
            </div>

            {{-- ------------------------------------------------------- picture --}}
            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6 space-y-4">
                <div class="flex items-center justify-between gap-4">
                    <h3 class="text-xs uppercase tracking-widest text-gray-500 dark:text-gray-400">
                        {{ __('Picture') }}
                    </h3>
                    <span class="text-xs text-gray-400 dark:text-gray-500" x-text="labelFor(image_status)"></span>
                </div>

                {{-- The one in place --}}
                <template x-if="image_url">
                    <img :src="image_url" alt=""
                         class="w-full max-h-64 object-contain rounded-md bg-gray-100 dark:bg-gray-900">
                </template>

                <template x-if="! image_url && image_status !== 'ready'">
                    <div class="h-40 rounded-md bg-gray-100 dark:bg-gray-900 flex items-center justify-center text-sm text-gray-400">
                        <span x-show="working">{{ __('Looking for a picture…') }}</span>
                        <span x-show="! working" x-cloak>{{ __('No picture yet.') }}</span>
                    </div>
                </template>

                <template x-if="errors.image">
                    <p class="text-sm text-red-700 dark:text-red-300" x-text="errors.image"></p>
                </template>

                {{-- Rewrite the scene and look again. The scene is what is searched,
                     never the sentence — stock libraries match tags. --}}
                <form method="POST" action="{{ route('notes.image.search', $note) }}" class="flex gap-2">
                    @csrf
                    <x-text-input name="image_query" type="text" :value="old('image_query', $query)"
                                  class="block w-full text-sm"
                                  placeholder="{{ __('two or three concrete nouns') }}" required />
                    <x-secondary-button type="submit" class="shrink-0">{{ __('Search') }}</x-secondary-button>
                </form>

                {{-- The grid. Six is enough to choose from without becoming a chore. --}}
                <div class="grid grid-cols-3 gap-2" x-show="candidates.length" x-cloak>
                    <template x-for="candidate in candidates" :key="candidate.index">
                        <form method="POST" action="{{ route('notes.image.choose', $note) }}">
                            @csrf
                            <input type="hidden" name="index" :value="candidate.index">
                            <button type="submit"
                                    class="block w-full rounded-md overflow-hidden ring-2 transition"
                                    :class="candidate.chosen
                                        ? 'ring-indigo-500'
                                        : 'ring-transparent hover:ring-gray-300 dark:hover:ring-gray-600'">
                                <img :src="candidate.thumbnail" alt=""
                                     class="w-full h-20 object-cover bg-gray-100 dark:bg-gray-900">
                                <span class="block px-1 py-1 text-[10px] truncate text-gray-400 dark:text-gray-500"
                                      x-text="candidate.credit"></span>
                            </button>
                        </form>
                    </template>
                </div>

                {{-- The way out when no library has the concept at all. --}}
                <form method="POST" action="{{ route('notes.image.upload', $note) }}"
                      enctype="multipart/form-data"
                      class="flex flex-wrap items-center gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                    @csrf
                    <label class="text-sm text-gray-500 dark:text-gray-400">{{ __('Or use your own:') }}</label>
                    <input type="file" name="image" accept="image/*" required
                           class="text-sm text-gray-600 dark:text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-sm file:bg-gray-100 dark:file:bg-gray-700 file:text-gray-700 dark:file:text-gray-200">
                    <x-secondary-button type="submit">{{ __('Upload') }}</x-secondary-button>
                </form>
            </div>

            {{-- --------------------------------------------------------- audio --}}
            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6 space-y-3">
                <div class="flex items-center justify-between gap-4">
                    <h3 class="text-xs uppercase tracking-widest text-gray-500 dark:text-gray-400">
                        {{ __('Audio') }}
                    </h3>
                    <span class="text-xs text-gray-400 dark:text-gray-500" x-text="labelFor(audio_status)"></span>
                </div>

                <template x-if="audio_sentence_url">
                    <div class="space-y-2">
                        <audio controls preload="none" class="w-full" :src="audio_sentence_url"></audio>
                        <template x-if="audio_target_url">
                            <audio controls preload="none" class="w-full" :src="audio_target_url"></audio>
                        </template>
                    </div>
                </template>

                <template x-if="! audio_sentence_url">
                    <p class="text-sm text-gray-400 dark:text-gray-500">
                        <span x-show="working">{{ __('Recording…') }}</span>
                        <span x-show="! working" x-cloak>{{ __('No audio yet.') }}</span>
                    </p>
                </template>

                <template x-if="errors.audio">
                    <p class="text-sm text-red-700 dark:text-red-300" x-text="errors.audio"></p>
                </template>
            </div>

            {{-- --------------------------------------------------------- finish --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="POST" action="{{ route('notes.media.retry', $note) }}">
                    @csrf
                    <button type="submit"
                            class="text-sm text-gray-500 dark:text-gray-400 underline hover:text-gray-900 dark:hover:text-gray-100">
                        {{ __('Try the missing part again') }}
                    </button>
                </form>

                <form method="POST" action="{{ route('notes.complete', $note) }}">
                    @csrf
                    <button type="submit" x-bind:disabled="! complete"
                            class="px-6 py-3 rounded-md font-semibold text-white transition
                                   disabled:opacity-40 disabled:cursor-not-allowed
                                   bg-emerald-600 hover:bg-emerald-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-emerald-500">
                        {{ __('Create the card') }}
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-gray-400 dark:text-gray-500" x-show="! complete" x-cloak>
                <span x-text="missingLabel()"></span>
            </p>

        </div>
    </div>

    @push('scripts')
        <script>
            function compose({ statusUrl, state }) {
                return {
                    ...state,
                    statusUrl,
                    labels: {
                        pending: @json(__('Queued')),
                        processing: @json(__('Working…')),
                        ready: @json(__('Ready')),
                        failed: @json(__('Failed')),
                    },
                    names: {
                        image_path: @json(__('a picture')),
                        audio_sentence_path: @json(__('audio')),
                        sentence: @json(__('the sentence')),
                        target: @json(__('a target word that appears in the sentence')),
                        meaning: @json(__('a meaning')),
                    },

                    get working() {
                        return ! this.settled;
                    },

                    labelFor(status) {
                        return this.labels[status] ?? status;
                    },

                    missingLabel() {
                        const parts = this.missing.map((key) => this.names[key] ?? key);

                        return parts.length
                            ? @json(__('Still needs')) + ' ' + parts.join(', ') + '.'
                            : '';
                    },

                    // Stops the moment nothing is expected to change on its own,
                    // so an abandoned tab is not a request every two seconds
                    // forever.
                    async poll() {
                        // The first check goes out straight away. A job that
                        // finished between the redirect and this page rendering
                        // has already finished, and waiting two seconds to find
                        // that out is two seconds of watching a spinner for
                        // nothing.
                        let wait = 0;

                        while (! this.settled) {
                            await new Promise((resolve) => setTimeout(resolve, wait));
                            wait = 2000;

                            try {
                                const response = await fetch(this.statusUrl, {
                                    headers: { 'Accept': 'application/json' },
                                });

                                if (! response.ok) {
                                    break;
                                }

                                Object.assign(this, await response.json());
                            } catch (e) {
                                break;
                            }
                        }
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
