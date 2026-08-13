@props(['note'])

@php
    use App\Enums\AssetStatus;

    $assets = [
        'Picture' => $note->image_status,
        'Audio' => $note->audio_status,
    ];

    $classes = fn (AssetStatus $status) => match ($status) {
        AssetStatus::Ready => 'text-emerald-600 dark:text-emerald-500',
        AssetStatus::Failed => 'text-amber-600 dark:text-amber-500',
        default => 'text-gray-400 dark:text-gray-500',
    };
@endphp

<span class="flex flex-wrap gap-x-3 gap-y-1 text-xs">
    @foreach ($assets as $label => $status)
        @if ($status !== AssetStatus::Ready)
            <span class="{{ $classes($status) }}">
                {{ $label }}: {{ $status->label() }}
            </span>
        @endif
    @endforeach
</span>
