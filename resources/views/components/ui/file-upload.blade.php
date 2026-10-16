@props([
    'label' => null,
    'name' => null,
    'description' => null,
])

@php
    $name ??= $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? \App\Support\FormField::id($name);
    $error = $name && isset($errors) ? $errors->first($name) : null;
    $required = (bool) $attributes->get('required', false);
@endphp

{{-- A Livewire upload (wire:model on a property of a component that uses WithFileUploads). The file goes up as soon
     as it is chosen; the bar shows how far, and the component's Save checks it. --}}
<div
    class="grid content-start gap-1.5"
    x-data="{ uploading: false, progress: 0, failed: false }"
    x-on:livewire-upload-start="uploading = true; failed = false"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-cancel="uploading = false"
    x-on:livewire-upload-error="uploading = false; failed = true"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
>
    @if ($label)
        <label for="{{ $id }}" class="text-sm font-medium">{{ $label }}@if ($required)<span class="text-red-600 dark:text-red-400" aria-hidden="true"> *</span>@endif</label>
    @endif

    <input
        id="{{ $id }}"
        type="file"
        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except('id')->class([
            'block w-full rounded-lg border bg-white text-sm text-zinc-700 shadow-xs file:me-3 file:border-0 file:border-e file:border-zinc-300 file:bg-zinc-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-800 focus:outline-2 focus:outline-offset-1 focus:outline-deep-green disabled:opacity-60 dark:bg-zinc-800 dark:text-zinc-200 dark:file:border-zinc-600 dark:file:bg-zinc-700 dark:file:text-white dark:focus:outline-signal',
            'border-red-500 dark:border-red-500' => $error,
            'border-zinc-300 dark:border-zinc-600' => ! $error,
        ]) }}
    />

    <div x-show="uploading" x-cloak class="h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="progress" aria-label="{{ __('Uploading') }}">
        <div class="h-full bg-deep-green transition-[width] dark:bg-signal" x-bind:style="`width: ${progress}%`"></div>
    </div>

    <p x-show="failed" x-cloak role="alert" class="text-sm text-red-600 dark:text-red-400">{{ __('The file could not be uploaded. It may be larger than 10 MB. Choose it again.') }}</p>

    @if ($description)
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
    @endif

    @if ($error)
        <p id="{{ $id }}-error" class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif
</div>
