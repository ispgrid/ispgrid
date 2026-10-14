@props([
    'label' => null,
    'name' => null,
    'options' => [],
    'placeholder' => null,
    'selected' => null,
    'description' => null,
])

@php
    $name ??= $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? \App\Support\FormField::id($name);
    $error = $name && isset($errors) ? $errors->first($name) : null;
    $required = (bool) $attributes->get('required', false);
@endphp

<div class="grid content-start gap-1.5">
    @if ($label)
        <label for="{{ $id }}" class="text-sm font-medium">{{ $label }}@if ($required)<span class="text-red-600 dark:text-red-400" aria-hidden="true"> *</span>@endif</label>
    @endif

    <select
        id="{{ $id }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except('id')->class([
            'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-zinc-900 shadow-xs focus:outline-2 focus:outline-offset-1 focus:outline-deep-green dark:focus:outline-signal dark:bg-zinc-800 dark:text-white',
            'border-red-500' => $error,
            'border-zinc-300 dark:border-zinc-600' => ! $error,
        ]) }}
    >
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected($selected !== null && (string) $value === (string) $selected)>{{ $text }}</option>
        @endforeach
    </select>

    @if ($description)
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
    @endif

    @if ($error)
        <p id="{{ $id }}-error" class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif
</div>
