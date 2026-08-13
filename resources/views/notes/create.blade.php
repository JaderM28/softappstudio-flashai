@php
    $generated = session('generated', []);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Add a sentence') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-flash-status />

            @if ($generatorAvailable)
                <form method="POST" action="{{ route('notes.generate') }}"
                      x-data="{ working: false }" @submit="working = true"
                      class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6 space-y-3">
                    @csrf
                    <input type="hidden" name="deck_id" value="{{ $selectedDeckId }}">

                    <x-input-label for="input" :value="__('Word or sentence')" />
                    <textarea id="input" name="input" rows="2" autofocus
                              placeholder="{{ __('borrowed — or paste the whole sentence you met') }}"
                              class="block w-full text-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-xs">{{ old('input', session('generatedFrom')) }}</textarea>
                    <x-input-error :messages="$errors->get('input')" class="mt-1" />

                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('A word gets a sentence written around it. A sentence gets its key word picked out.') }}
                        </p>
                        <x-primary-button x-bind:disabled="working" class="shrink-0">
                            <span x-show="!working">{{ __('Generate') }}</span>
                            <span x-show="working" x-cloak>{{ __('Working…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            @endif

            @if ($generated !== [])
                <div class="rounded-md border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/30 px-4 py-3 text-sm text-indigo-800 dark:text-indigo-200">
                    {{ __('Nothing is saved yet. Check it over and correct anything odd before saving.') }}
                </div>
            @endif

            @if (session('targetWarning'))
                <div class="rounded-md border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                    {{ __('The word below does not appear in the sentence exactly as written, so no fill-in-the-blank card can be built. Adjust one of them to match.') }}
                </div>
            @endif

            <form method="POST" action="{{ route('notes.store') }}"
                  class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6">
                @csrf

                @include('notes.partials.form', [
                    'decks' => $decks,
                    'selectedDeckId' => $selectedDeckId,
                    'generated' => $generated,
                ])

                <div class="mt-6 flex items-center justify-end gap-3">
                    <a href="{{ route('dashboard') }}"
                       class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
                        {{ __('Done') }}
                    </a>
                    <x-primary-button>{{ __('Save and add another') }}</x-primary-button>
                </div>
            </form>

        </div>
    </div>
</x-app-layout>
