<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Today') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <x-flash-status />

            {{-- The number the whole app exists to bring down. --}}
            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6 sm:p-8 text-center">
                <p class="text-sm uppercase tracking-widest text-gray-500 dark:text-gray-400">
                    {{ __('Due now') }}
                </p>
                <p class="mt-2 text-6xl sm:text-7xl font-bold tabular-nums text-gray-900 dark:text-gray-100">
                    {{ $dueCount }}
                </p>

                @if ($dueCount > 0)
                    <div class="mt-4 flex flex-wrap justify-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                        @foreach ($counts as $queue => $count)
                            @if ($count > 0)
                                <span class="tabular-nums">
                                    {{ $count }} {{ \App\Enums\CardQueue::from($queue)->label() }}
                                </span>
                            @endif
                        @endforeach
                    </div>

                    <a href="{{ route('review.show') }}"
                       class="mt-6 inline-flex w-full sm:w-auto justify-center items-center px-8 py-4 bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800 rounded-md font-semibold text-lg tracking-wide hover:bg-gray-700 dark:hover:bg-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-800 transition">
                        {{ __('Start review') }}
                    </a>
                @else
                    <p class="mt-4 text-gray-500 dark:text-gray-400">
                        {{ $noteCount === 0
                            ? __('Nothing here yet. Add your first sentence.')
                            : __('Nothing due. Come back tomorrow.') }}
                    </p>
                @endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <a href="{{ route('notes.create') }}"
                   class="block bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-5 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 transition">
                    <p class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Add a sentence') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Type or paste one you met in the wild') }}</p>
                </a>

                <a href="{{ route('notes.index') }}"
                   class="block bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-5 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 transition">
                    <p class="font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('Browse') }}
                        <span class="text-gray-400 tabular-nums">{{ $noteCount }}</span>
                    </p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Search, edit and delete sentences') }}</p>
                </a>
            </div>

            @if ($noteCount > 0)
                <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-5">
                    <p class="text-sm uppercase tracking-widest text-gray-500 dark:text-gray-400 mb-3">
                        {{ __('Cards') }}
                    </p>
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        @foreach ($states as $state => $count)
                            <div>
                                <dt class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ \App\Enums\CardState::from($state)->label() }}
                                </dt>
                                <dd class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ $count }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
