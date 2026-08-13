@if (session('status'))
    <div class="rounded-md bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
        {{ session('status') }}
    </div>
@endif
