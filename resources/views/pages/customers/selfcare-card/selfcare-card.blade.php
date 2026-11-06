<div>
    <x-ui.card class="grid grid-cols-1 gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <x-ui.heading>{{ __('Selfcare') }}</x-ui.heading>
                <x-ui.text muted class="mt-1 text-sm">{{ __('The customer logs in at :url with their username and this password.', ['url' => $this->selfcareUrl]) }}</x-ui.text>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button size="sm" variant="secondary" icon="key" wire:click="setPassword">{{ __('Set selfcare password') }}</x-ui.button>
                @if ($this->canSend)
                    <x-ui.button size="sm" variant="secondary" wire:click="send" wire:confirm="{{ __('This sets a new password and sends it to the customer. The old one stops working.') }}">{{ __('Send by SMS/WhatsApp') }}</x-ui.button>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Password') }}</dt>
                <dd>
                    @if ($this->status['set'] === null)
                        {{ __('No selfcare password yet.') }}
                    @else
                        {{ __('Set on :date', ['date' => $this->status['set']]) }}
                        @if ($this->status['must_change'])
                            <span class="text-zinc-600 dark:text-zinc-400">({{ __('the customer has not chosen their own yet') }})</span>
                        @endif
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Last login') }}</dt>
                <dd>{{ $this->status['last_login'] ?? __('Never') }}</dd>
            </div>
            @if ($this->lastMessage)
                <div class="sm:col-span-2">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Last message') }}</dt>
                    <dd class="flex flex-wrap items-center gap-2">
                        {{ $this->lastMessage['when'] }}
                        <x-ui.badge :color="$this->lastMessage['color']">{{ $this->lastMessage['status'] }}</x-ui.badge>
                        @if ($this->lastMessage['error'])
                            <span class="text-zinc-600 dark:text-zinc-400">{{ $this->lastMessage['error'] }}</span>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>

        <form wire:submit="setPassword($wire.typed)" class="flex flex-wrap items-end gap-2" novalidate>
            <div class="min-w-48 flex-1">
                <x-ui.input wire:model="typed" name="typed" :label="__('Or type a password')" autocomplete="off" />
            </div>
            <x-ui.button type="submit" size="sm" variant="secondary">{{ __('Set this password') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.modal name="selfcare-password" x-on:close="$wire.closeDialog()">
        @if ($this->details !== null)
            <div class="grid grid-cols-1 gap-4" x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(@js($this->details)); this.copied = true; setTimeout(() => this.copied = false, 1500); } catch (e) { console.warn('Could not copy to clipboard'); } } }">
                <x-ui.heading size="lg">{{ __('Selfcare password set') }}</x-ui.heading>
                <x-ui.alert>{{ __('This password is shown only now.') }}</x-ui.alert>
                <p class="font-mono text-2xl tracking-wider">{{ $this->shownPassword }}</p>
                <pre class="rounded-lg bg-zinc-100 p-3 text-sm whitespace-pre-wrap dark:bg-zinc-800">{{ $this->details }}</pre>
                <div class="flex flex-wrap justify-end gap-2">
                    <x-ui.button variant="secondary" icon="document-duplicate" x-on:click="copy()">
                        <span x-show="! copied">{{ __('Copy selfcare details') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </x-ui.button>
                    <x-ui.button x-on:click="$dispatch('modal-close', { name: 'selfcare-password' })">{{ __('Done') }}</x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
