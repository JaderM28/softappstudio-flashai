<x-app-layout>
    <div class="py-12">
        <div class="max-w-xl mx-auto px-4 sm:px-6 text-center space-y-6">

            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-8 sm:p-10">
                <p class="text-5xl" aria-hidden="true">✓</p>
                <h1 class="mt-4 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('Nothing left due') }}
                </h1>
                <p class="mt-2 text-gray-500 dark:text-gray-400">
                    @if ($reviewedToday > 0)
                        {{ trans_choice('{1} :count card reviewed today.|[2,*] :count cards reviewed today.', $reviewedToday, ['count' => $reviewedToday]) }}
                    @else
                        {{ __('Come back when something falls due.') }}
                    @endif
                </p>

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

                @if ($canUndo)
                    <form method="POST" action="{{ route('review.undo') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 underline hover:text-gray-900 dark:hover:text-gray-100">
                            {{ __('Undo last answer') }}
                        </button>
                    </form>
                @endif

                <div class="mt-6 flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="{{ route('notes.create') }}"
                       class="inline-flex justify-center px-5 py-3 rounded-md bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800 font-semibold hover:bg-gray-700 dark:hover:bg-white focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 transition">
                        {{ __('Add a sentence') }}
                    </a>
                    <a href="{{ route('dashboard') }}"
                       class="inline-flex justify-center px-5 py-3 rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-indigo-500 transition">
                        {{ __('Back to today') }}
                    </a>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
