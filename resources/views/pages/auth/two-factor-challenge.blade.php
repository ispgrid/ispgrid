<x-layouts::auth :title="__('Two-factor authentication')">
    <div
        class="flex flex-col gap-6"
        x-data="{
            showRecoveryInput: @js($errors->has('recovery_code')),
            focusInput() {
                this.$nextTick(() => (this.showRecoveryInput ? this.$refs.recovery_code : this.$refs.code)?.focus());
            },
            toggleInput() {
                this.showRecoveryInput = ! this.showRecoveryInput;
                this.focusInput();
            },
        }"
        x-init="focusInput()"
    >
        <div x-show="! showRecoveryInput">
            <x-auth-header :title="__('Authentication code')" :description="__('Enter the authentication code provided by your authenticator application.')" />
        </div>

        <div x-show="showRecoveryInput" x-cloak>
            <x-auth-header :title="__('Recovery code')" :description="__('Please confirm access to your account by entering one of your emergency recovery codes.')" />
        </div>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="flex flex-col gap-5">
            @csrf

            <div x-show="! showRecoveryInput">
                <x-ui.input
                    name="code"
                    :label="__('Authentication code')"
                    x-ref="code"
                    x-bind:disabled="showRecoveryInput"
                    inputmode="numeric"
                    maxlength="6"
                    autocomplete="one-time-code"
                    class="text-center font-mono tracking-[0.5em]"
                />
            </div>

            <div x-show="showRecoveryInput" x-cloak>
                <x-ui.input
                    name="recovery_code"
                    :label="__('Recovery code')"
                    x-ref="recovery_code"
                    x-bind:disabled="! showRecoveryInput"
                    autocomplete="one-time-code"
                />
            </div>

            <x-ui.button type="submit" class="w-full">{{ __('Continue') }}</x-ui.button>
        </form>

        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('or you can') }}
            <button type="button" class="font-medium underline" x-on:click="toggleInput()">
                <span x-show="! showRecoveryInput">{{ __('log in using a recovery code') }}</span>
                <span x-show="showRecoveryInput" x-cloak>{{ __('log in using an authentication code') }}</span>
            </button>
        </p>
    </div>
</x-layouts::auth>
