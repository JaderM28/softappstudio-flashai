<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Add a sentence') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-flash-status />

            <form method="POST" action="{{ route('notes.store') }}"
                  class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6">
                @csrf

                @include('notes.partials.form', [
                    'decks' => $decks,
                    'selectedDeckId' => $selectedDeckId,
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
