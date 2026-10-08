<div class="grid gap-6 rounded-xl border border-zinc-200 py-6 shadow-sm dark:border-zinc-700" wire:cloak x-data="{ showRecoveryCodes: false }">
    <div class="grid gap-2 px-6">
        <div class="flex items-center gap-2">
            <x-ui.icon name="lock-closed" class="size-4" />
            <x-ui.heading level="3" size="lg">{{ __('2FA recovery codes') }}</x-ui.heading>
        </div>
        <x-ui.text muted>{{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}</x-ui.text>
    </div>

    <div class="grid gap-3 px-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-ui.button icon="eye" x-show="! showRecoveryCodes" x-on:click="showRecoveryCodes = true" aria-controls="recovery-codes-section">{{ __('View recovery codes') }}</x-ui.button>
            <x-ui.button icon="eye-slash" x-show="showRecoveryCodes" x-cloak x-on:click="showRecoveryCodes = false" aria-controls="recovery-codes-section">{{ __('Hide recovery codes') }}</x-ui.button>

            @if (filled($recoveryCodes))
                <x-ui.button variant="secondary" icon="arrow-path" x-show="showRecoveryCodes" x-cloak wire:click="regenerateRecoveryCodes">{{ __('Regenerate codes') }}</x-ui.button>
            @endif
        </div>

        <div x-show="showRecoveryCodes" x-cloak x-transition id="recovery-codes-section" class="grid gap-3">
            @error('recoveryCodes')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            @if (filled($recoveryCodes))
                <div class="grid gap-1 rounded-lg bg-zinc-100 p-4 font-mono text-sm dark:bg-white/5" role="list" aria-label="{{ __('Recovery codes') }}">
                    @foreach ($recoveryCodes as $code)
                        <div role="listitem" class="select-text" wire:loading.class="animate-pulse opacity-50">{{ $code }}</div>
                    @endforeach
                </div>
                <x-ui.text muted class="text-xs">{{ __('Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.') }}</x-ui.text>
            @endif
        </div>
    </div>
</div>
