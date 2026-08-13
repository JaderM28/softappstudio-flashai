<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Sentences') }}
            </h2>
            <a href="{{ route('notes.create') }}"
               class="text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                {{ __('Add') }}
            </a>
        </div>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-flash-status />

            <form method="GET" action="{{ route('notes.index') }}">
                <x-text-input name="q" type="search" :value="$query"
                              placeholder="{{ __('Search sentences, words or meanings') }}"
                              class="block w-full" />
            </form>

            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($notes as $note)
                    <div class="p-4 sm:p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-gray-900 dark:text-gray-100">
                                    {{ $note->sentence }}
                                </p>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $note->target }}</span>
                                    @if ($note->meaning)
                                        — {{ $note->meaning }}
                                    @endif
                                </p>
                                <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400 dark:text-gray-500">
                                    <span>{{ $note->deck->name }}</span>
                                    @foreach ($note->cards as $card)
                                        <span class="tabular-nums">
                                            {{ $card->type->label() }}: {{ $card->state->label() }}
                                        </span>
                                    @endforeach
                                    @if ($note->cards->isEmpty())
                                        <span class="text-amber-600 dark:text-amber-500">
                                            {{ __('No cards yet') }}
                                        </span>
                                    @endif
                                    <x-asset-status :note="$note" />
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-3 text-sm">
                                <a href="{{ route('notes.edit', $note) }}"
                                   class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                    {{ __('Edit') }}
                                </a>
                                <form method="POST" action="{{ route('notes.destroy', $note) }}"
                                      onsubmit="return confirm('{{ __('Delete this sentence and its cards?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 dark:text-red-400 hover:underline">
                                        {{ __('Delete') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                        {{ $query === ''
                            ? __('No sentences yet.')
                            : __('Nothing matched that search.') }}
                    </div>
                @endforelse
            </div>

            {{ $notes->links() }}

        </div>
    </div>
</x-app-layout>
