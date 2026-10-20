<x-ui.modal name="two-factor-setup-modal" x-on:close="$wire.closeModal()">
    <div class="grid gap-6">
        <div class="flex flex-col items-center gap-4 text-center">
            <div class="flex size-14 items-center justify-center rounded-full border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800">
                <x-ui.icon name="qr-code" class="size-7" />
            </div>
            <div class="grid gap-2">
                <x-ui.heading size="lg">{{ $this->modalConfig['title'] }}</x-ui.heading>
                <x-ui.text>{{ $this->modalConfig['description'] }}</x-ui.text>
            </div>
        </div>

        @if ($showVerificationStep)
            <div class="grid gap-6" x-data x-init="$nextTick(() => $el.querySelector('input')?.focus())">
                <x-ui.input
                    wire:model="code"
                    :label="__('Authentication code')"
                    inputmode="numeric"
                    maxlength="6"
                    autocomplete="one-time-code"
                    class="text-center font-mono tracking-[0.5em]"
                />

                <div class="flex gap-3">
                    <x-ui.button variant="secondary" class="flex-1" wire:click="resetVerification">{{ __('Back') }}</x-ui.button>
                    <x-ui.button class="flex-1" wire:click="confirmTwoFactor" x-bind:disabled="$wire.code.length < 6">{{ __('Confirm') }}</x-ui.button>
                </div>
            </div>
        @else
            @error('setupData')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex justify-center">
                <div class="flex aspect-square w-64 items-center justify-center overflow-hidden rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700">
                    @empty($qrCodeSvg)
                        <x-ui.icon name="arrow-path" class="size-6 animate-spin text-zinc-400" />
                    @else
                        {!! $qrCodeSvg !!}
                    @endempty
                </div>
            </div>

            <x-ui.button class="w-full" :disabled="$errors->has('setupData')" wire:click="showVerificationIfNecessary">{{ $this->modalConfig['buttonText'] }}</x-ui.button>

            <div class="grid gap-3">
                <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">{{ __('or, enter the code manually') }}</p>

                <div
                    class="flex items-stretch overflow-hidden rounded-lg border border-zinc-300 dark:border-zinc-600"
                    x-data="{
                        copied: false,
                        async copy() {
                            try {
                                await navigator.clipboard.writeText(@js($manualSetupKey));
                                this.copied = true;
                                setTimeout(() => this.copied = false, 1500);
                            } catch (e) {
                                console.warn('Could not copy to clipboard');
                            }
                        },
                    }"
                >
                    @empty($manualSetupKey)
                        <div class="flex w-full items-center justify-center p-3">
                            <x-ui.icon name="arrow-path" class="size-4 animate-spin text-zinc-400" />
                        </div>
                    @else
                        <input type="text" readonly value="{{ $manualSetupKey }}" class="w-full bg-transparent p-3 font-mono text-sm outline-none" aria-label="{{ __('Setup key') }}" />
                        <button type="button" x-on:click="copy()" class="border-s border-zinc-300 px-3 dark:border-zinc-600" aria-label="{{ __('Copy setup key') }}">
                            <x-ui.icon name="document-duplicate" class="size-5" x-show="! copied" />
                            <x-ui.icon name="check" class="size-5 text-deep-green dark:text-signal" x-show="copied" x-cloak />
                        </button>
                    @endempty
                </div>
            </div>
        @endif
    </div>
</x-ui.modal>
