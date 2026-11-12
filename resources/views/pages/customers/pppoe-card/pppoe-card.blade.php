<div>
    <x-ui.card class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <x-ui.heading>{{ __('PPPoE password') }}</x-ui.heading>
            <x-ui.text muted class="mt-1 text-sm">{{ __('A new password disconnects the customer. Their router needs the new one to get online again.') }}</x-ui.text>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button size="sm" variant="secondary" icon="key" wire:click="resetPassword" wire:confirm="{{ __('Reset the PPPoE password? The customer is disconnected and must use the new password.') }}">{{ __('Reset PPPoE password') }}</x-ui.button>
            @if ($this->canSend)
                <x-ui.button size="sm" variant="secondary" wire:click="resetAndSend" wire:confirm="{{ __('Reset the PPPoE password and send it to the customer? They are disconnected and must use the new password.') }}">{{ __('Reset and send by SMS/WhatsApp') }}</x-ui.button>
            @endif
        </div>
    </x-ui.card>

    <x-ui.modal name="pppoe-password" x-on:close="$wire.closeDialog()">
        @if ($this->details !== null)
            <div class="grid grid-cols-1 gap-4" x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(@js($this->details)); this.copied = true; setTimeout(() => this.copied = false, 1500); } catch (e) { console.warn('Could not copy to clipboard'); } } }">
                <x-ui.heading size="lg">{{ __('PPPoE password reset') }}</x-ui.heading>
                <x-ui.alert>{{ __('This password is shown only now.') }}</x-ui.alert>
                <p class="font-mono text-2xl tracking-wider">{{ $this->shownPassword }}</p>
                <pre class="rounded-lg bg-zinc-100 p-3 text-sm whitespace-pre-wrap dark:bg-zinc-800">{{ $this->details }}</pre>
                <div class="flex flex-wrap justify-end gap-2">
                    <x-ui.button variant="secondary" icon="document-duplicate" x-on:click="copy()">
                        <span x-show="! copied">{{ __('Copy login') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </x-ui.button>
                    <x-ui.button x-on:click="$dispatch('modal-close', { name: 'pppoe-password' })">{{ __('Done') }}</x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
