@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'description' => null,
    'viewable' => false,
])

@php
    $name ??= $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? \App\Support\FormField::id($name);
    $error = $name && isset($errors) ? $errors->first($name) : null;
@endphp

<div class="grid gap-1.5">
    @if ($label)
        <label for="{{ $id }}" class="text-sm font-medium">{{ $label }}</label>
    @endif

    <div class="relative" @if ($viewable) x-data="{ visible: false }" @endif>
        <input
            id="{{ $id }}"
            @if ($name) name="{{ $name }}" @endif
            type="{{ $type }}"
            @if ($viewable) x-bind:type="visible ? 'text' : 'password'" @endif
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->except('id')->class([
                'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-zinc-900 shadow-xs placeholder:text-zinc-400 focus:outline-2 focus:outline-offset-1 focus:outline-zinc-500 disabled:opacity-60 dark:bg-zinc-800 dark:text-white',
                'border-red-500 dark:border-red-500' => $error,
                'border-zinc-300 dark:border-zinc-600' => ! $error,
                'pe-10' => $viewable,
            ]) }}
        />

        @if ($viewable)
            <button
                type="button"
                x-on:click="visible = ! visible"
                x-bind:aria-label="visible ? @js(__('Hide password')) : @js(__('Show password'))"
                class="absolute inset-y-0 end-0 flex items-center px-3 text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
            >
                <x-ui.icon name="eye" class="size-4" x-show="! visible" />
                <x-ui.icon name="eye-slash" class="size-4" x-show="visible" x-cloak />
            </button>
        @endif
    </div>

    @if ($description)
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
    @endif

    @if ($error)
        <p id="{{ $id }}-error" class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif
</div>
