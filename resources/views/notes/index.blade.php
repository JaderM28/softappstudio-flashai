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

            <form method="GET" action="{{ route('notes.index') }}" class="space-y-3">
                <x-text-input name="q" type="search" :value="$query"
                              placeholder="{{ __('Search sentences, words or meanings') }}"
                              class="block w-full" />

                @php
                    $tabs = [
                        '' => __('All'),
                        'ready' => __('Studying'),
                        'incomplete' => __('Unfinished'),
                    ];
                @endphp

                <div class="flex flex-wrap items-center gap-2 text-sm">
                    @foreach ($tabs as $value => $label)
                        <a href="{{ route('notes.index', array_filter(['q' => $query, 'status' => $value])) }}"
                           class="px-3 py-1 rounded-full transition {{ $status === $value
                               ? 'bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800'
                               : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                            {{ $label }}
                            @if ($value === 'incomplete' && $unfinished > 0)
                                <span class="ms-1 tabular-nums">{{ $unfinished }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </form>

            @if ($unfinished > 0 && $status !== 'incomplete')
                <div class="rounded-md border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                    {{ trans_choice(
                        '{1} One sentence is still waiting for its picture or its audio.|[2,*] :count sentences are still waiting for their picture or audio.',
                        $unfinished,
                        ['count' => $unfinished],
                    ) }}
                    <a href="{{ route('notes.index', ['status' => 'incomplete']) }}" class="underline font-medium">
                        {{ __('Finish them') }}
                    </a>
                </div>
            @endif

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
                                        <a href="{{ route('notes.compose', $note) }}"
                                           class="font-medium text-amber-600 dark:text-amber-500 hover:underline">
                                            {{ __('Finish this card') }}
                                        </a>
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
