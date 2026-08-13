<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Edit sentence') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <form method="POST" action="{{ route('notes.update', $note) }}"
                  class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6">
                @csrf
                @method('PATCH')

                @include('notes.partials.form', [
                    'note' => $note,
                    'decks' => $decks,
                    'selectedDeckId' => $selectedDeckId,
                ])

                <div class="mt-6 flex items-center justify-end gap-3">
                    <a href="{{ route('notes.index') }}"
                       class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
                        {{ __('Cancel') }}
                    </a>
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                </div>
            </form>

            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6 space-y-4">
                <p class="text-sm uppercase tracking-widest text-gray-500 dark:text-gray-400">
                    {{ __('Picture and audio') }}
                </p>

                @if ($note->imageSrc())
                    <img src="{{ $note->imageSrc() }}" alt=""
                         class="w-full h-40 object-cover rounded-md bg-gray-100 dark:bg-gray-900">
                    @if ($note->image_attribution)
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            {{ __('Photo by') }}
                            <a href="{{ $note->image_attribution['profile_url'] ?? '#' }}"
                               target="_blank" rel="noopener noreferrer" class="underline">
                                {{ $note->image_attribution['photographer'] ?? __('Unknown') }}
                            </a>
                            {{ __('on Unsplash') }}
                        </p>
                    @endif
                @endif

                @if ($note->audioSentenceSrc())
                    <audio controls preload="none" src="{{ $note->audioSentenceSrc() }}" class="w-full"></audio>
                @endif

                @if ($note->image_status !== \App\Enums\AssetStatus::Ready || $note->audio_status !== \App\Enums\AssetStatus::Ready)
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="space-y-1">
                            <x-asset-status :note="$note" />
                            @foreach ($note->generation_errors ?? [] as $asset => $message)
                                <p class="text-xs text-amber-600 dark:text-amber-500">{{ $message }}</p>
                            @endforeach
                        </div>

                        <form method="POST" action="{{ route('notes.retry-media', $note) }}">
                            @csrf
                            <button type="submit"
                                    class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                                {{ __('Try again') }}
                            </button>
                        </form>
                    </div>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6">
                <p class="text-sm uppercase tracking-widest text-gray-500 dark:text-gray-400 mb-3">
                    {{ __('Cards from this sentence') }}
                </p>

                @forelse ($note->cards as $card)
                    <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-700 last:border-0">
                        <span class="text-gray-900 dark:text-gray-100">{{ $card->type->label() }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400 tabular-nums">
                            {{ $card->state->label() }}
                            @if ($card->interval_days > 0)
                                · {{ $card->interval_days }}d
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('None yet. A card appears once the sentence has what its question needs.') }}
                    </p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
