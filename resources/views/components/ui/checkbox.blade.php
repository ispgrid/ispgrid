@props(['label', 'name' => null, 'description' => null])

@php
    $name ??= $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? \App\Support\FormField::id($name);
    $error = $name && isset($errors) ? $errors->first($name) : null;
@endphp

<div class="flex items-start gap-2">
    <input
        type="checkbox"
        id="{{ $id }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except('id')->class('mt-0.5 size-4 rounded border-zinc-300 text-ink accent-ink focus:ring-deep-green dark:accent-signal dark:border-zinc-600 dark:bg-zinc-800') }}
    />
    <div class="grid gap-0.5">
        <label for="{{ $id }}" class="text-sm font-medium">{{ $label }}</label>
        @if ($description)
            <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
        @endif
        @if ($error)
            <p id="{{ $id }}-error" class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
        @endif
    </div>
</div>
