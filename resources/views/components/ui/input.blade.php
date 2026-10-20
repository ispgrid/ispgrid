@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'description' => null,
    'viewable' => false,
    // The value goes to FreeRADIUS, which reads English letters, digits and symbols only: warn while other text is typed.
    'english' => false,
])

@php
    $name ??= $attributes->wire('model')->value();
    $id = $attributes->get('id') ?? \App\Support\FormField::id($name);
    $error = $name && isset($errors) ? $errors->first($name) : null;
    $required = (bool) $attributes->get('required', false);
@endphp

<div class="grid content-start gap-1.5" @if ($english) x-data="{ notEnglish: false }" @endif>
    @if ($label)
        <label for="{{ $id }}" class="text-sm font-medium">{{ $label }}@if ($required)<span class="text-red-600 dark:text-red-400" aria-hidden="true"> *</span>@endif</label>
    @endif

    <div class="relative" @if ($viewable) x-data="{ visible: false }" @endif>
        <input
            id="{{ $id }}"
            @if ($name) name="{{ $name }}" @endif
            type="{{ $type }}"
            @if ($viewable) x-bind:type="visible ? 'text' : 'password'" @endif
            @if ($english) lang="en" dir="ltr" spellcheck="false" autocapitalize="off" x-on:input="notEnglish = /[^\x20-\x7E]/.test($event.target.value)" @endif
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->except('id')->class([
                'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-zinc-900 shadow-xs placeholder:text-zinc-400 focus:outline-2 focus:outline-offset-1 focus:outline-deep-green dark:focus:outline-signal disabled:opacity-60 dark:bg-zinc-800 dark:text-white',
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
                class="absolute inset-y-0 end-0 flex items-center px-3 text-zinc-600 dark:text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200"
            >
                <x-ui.icon name="eye" class="size-4" x-show="! visible" />
                <x-ui.icon name="eye-slash" class="size-4" x-show="visible" x-cloak />
            </button>
        @endif
    </div>

    @if ($english)
        <p x-show="notEnglish" x-cloak role="alert" class="text-sm text-amber-700 dark:text-amber-400">{{ __('Only English letters, digits and symbols: FreeRADIUS cannot read other characters.') }}</p>
    @endif

    @if ($description)
        <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
    @endif

    @if ($error)
        <p id="{{ $id }}-error" class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif
</div>
