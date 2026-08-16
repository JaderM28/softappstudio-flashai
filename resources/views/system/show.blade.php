@php
    use App\Enums\ProbeStatus;
    use App\Services\Diagnostics\SystemCheck;
    use App\Support\ProbeResult;

    $dot = fn (ProbeResult $p) => match ($p->status) {
        ProbeStatus::Ok => 'bg-emerald-500',
        ProbeStatus::Failed => 'bg-red-500',
        ProbeStatus::Skipped => 'bg-gray-300 dark:bg-gray-600',
    };

    $groupLabels = [
        'image' => __('Pictures'),
        'audio' => __('Audio'),
        'sentence' => __('Sentences'),
        'infra' => __('Storage, queue and tools'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('System status') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- What to probe with --}}
            <form method="POST" action="{{ route('system.run') }}"
                  x-data="{ working: false }" @submit="working = true"
                  class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6 space-y-4">
                @csrf

                <div>
                    <x-input-label for="phrase" :value="__('Phrase to search a picture for and read aloud')" />
                    <x-text-input id="phrase" name="phrase" type="text" :value="old('phrase', $phrase)"
                                  class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('phrase')" class="mt-1" />
                </div>

                <div class="flex flex-wrap items-end gap-4">
                    <div class="w-28">
                        <x-input-label for="language" :value="__('Language')" />
                        <x-text-input id="language" name="language" type="text" :value="old('language', $language)"
                                      class="mt-1 block w-full" required />
                    </div>

                    <div class="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-300">
                        @foreach (SystemCheck::GROUPS as $group)
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="groups[]" value="{{ $group }}" checked
                                       class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500">
                                {{ $groupLabels[$group] }}
                            </label>
                        @endforeach
                    </div>

                    <x-primary-button x-bind:disabled="working" class="ms-auto">
                        <span x-show="!working">{{ __('Run the checks') }}</span>
                        <span x-show="working" x-cloak>{{ __('Calling the services…') }}</span>
                    </x-primary-button>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('These are real calls against the real services, and they spend real quota. Gemini speech allows only a few requests a minute.') }}
                </p>
            </form>

            {{-- Results --}}
            @if ($probes->isNotEmpty())
                @php $failures = $probes->filter(fn (ProbeResult $p) => $p->isProblem()); @endphp

                <div class="rounded-md px-4 py-3 text-sm
                            {{ $failures->isEmpty()
                                ? 'border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200'
                                : 'border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200' }}">
                    {{ $failures->isEmpty()
                        ? __('Everything answered.')
                        : trans_choice('{1} :count check failed.|[2,*] :count checks failed.', $failures->count(), ['count' => $failures->count()]) }}
                </div>

                @foreach ($probes->groupBy('group') as $group => $groupProbes)
                    <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg divide-y divide-gray-100 dark:divide-gray-700">
                        <h3 class="px-6 py-3 text-xs uppercase tracking-widest text-gray-500 dark:text-gray-400">
                            {{ $groupLabels[$group] ?? $group }}
                        </h3>

                        @foreach ($groupProbes as $probe)
                            <div class="px-6 py-4 space-y-2">
                                <div class="flex items-center gap-3">
                                    <span class="size-2.5 rounded-full shrink-0 {{ $dot($probe) }}" aria-hidden="true"></span>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $probe->service }}</span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ $probe->status->label() }}</span>
                                    @if ($probe->durationMs > 0)
                                        <span class="ms-auto text-xs tabular-nums text-gray-400 dark:text-gray-500">
                                            {{ number_format($probe->durationMs) }} ms
                                        </span>
                                    @endif
                                </div>

                                {{-- The whole technical message, never trimmed. This page exists
                                     because a friendly summary is what hid the last failure. --}}
                                @if ($probe->detail !== '')
                                    {{-- break-all, not break-words: these are mostly URLs and
                                         API messages with no spaces to wrap on, and without it
                                         one long query string widens the whole page. --}}
                                    <p class="text-sm break-all {{ $probe->isProblem()
                                        ? 'text-red-700 dark:text-red-300'
                                        : 'text-gray-500 dark:text-gray-400' }}">
                                        {{ $probe->detail }}
                                    </p>
                                @endif

                                @if ($probe->isProblem() && $probe->userMessage)
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('On a card this reads as:') }} “{{ $probe->userMessage }}”
                                    </p>
                                @endif

                                {{-- Seeing and hearing it is the point. A green tick that no one
                                     can check is what this whole screen is here to replace. --}}
                                @if (($probe->payload['extension'] ?? null) !== null)
                                    <audio controls preload="none" class="w-full max-w-sm"
                                           src="{{ $probe->payload['url'] }}"></audio>
                                @elseif (($probe->payload['path'] ?? null) !== null)
                                    <img src="{{ $probe->payload['url'] }}" alt=""
                                         class="rounded-md max-h-48 bg-gray-100 dark:bg-gray-900">
                                @elseif (($probe->payload['url'] ?? null) !== null)
                                    <img src="{{ $probe->payload['url'] }}" alt=""
                                         class="rounded-md max-h-40 bg-gray-100 dark:bg-gray-900">
                                    @if ($probe->payload['photographer'] ?? null)
                                        <p class="text-xs text-gray-400 dark:text-gray-500">
                                            {{ $probe->payload['photographer'] }}
                                        </p>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif

            {{-- Environment --}}
            <div class="bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg p-6">
                <h3 class="text-xs uppercase tracking-widest text-gray-500 dark:text-gray-400">
                    {{ __('Environment') }}
                </h3>
                <dl class="mt-3 text-sm">
                    @foreach ($environment as $label => $value)
                        <div class="flex justify-between gap-6 border-b border-gray-100 dark:border-gray-700/50 py-1.5 last:border-0">
                            <dt class="text-gray-500 dark:text-gray-400 shrink-0">{{ $label }}</dt>
                            <dd class="text-gray-900 dark:text-gray-100 text-right break-all font-mono text-xs">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

        </div>
    </div>
</x-app-layout>
