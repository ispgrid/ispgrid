<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form wire:submit="updatePassword" class="grid gap-5">
            <x-ui.input wire:model="current_password" type="password" :label="__('Current password')" required autocomplete="current-password" viewable />
            <x-ui.input
                wire:model="password"
                type="password"
                :label="__('New password')"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <x-ui.input
                wire:model="password_confirmation"
                type="password"
                :label="__('Confirm password')"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div>
                <x-ui.button type="submit" data-test="update-password-button">{{ __('Save') }}</x-ui.button>
            </div>
        </form>

        @if ($canManageTwoFactor)
            <section class="mt-12 grid gap-4" wire:cloak>
                <div>
                    <x-ui.heading>{{ __('Two-factor authentication') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('Manage your two-factor authentication settings') }}</x-ui.text>
                </div>

                @if ($twoFactorEnabled)
                    <x-ui.text>{{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}</x-ui.text>

                    <div>
                        <x-ui.button variant="danger" wire:click="disable">{{ __('Disable 2FA') }}</x-ui.button>
                    </div>

                    <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                @else
                    <x-ui.text muted>{{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}</x-ui.text>

                    <div>
                        <x-ui.button
                            x-on:click="$dispatch('modal-open', { name: 'two-factor-setup-modal' })"
                            wire:click="$dispatch('start-two-factor-setup')"
                        >{{ __('Enable 2FA') }}</x-ui.button>
                    </div>

                    <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                @endif
            </section>
        @endif

        @if ($canManagePasskeys)
            <section class="mt-12 grid gap-4" wire:cloak>
                <div>
                    <x-ui.heading>{{ __('Passkeys') }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ __('Manage your passkeys for passwordless sign-in') }}</x-ui.text>
                </div>

                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    @forelse ($passkeys as $passkey)
                        <div wire:key="passkey-{{ $passkey['id'] }}" @class(['flex items-center justify-between gap-4 p-4', 'border-b border-zinc-200 dark:border-zinc-700' => ! $loop->last])>
                            <div class="flex items-center gap-4">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                    <x-ui.icon name="key" class="size-5 text-zinc-500 dark:text-zinc-400" />
                                </div>
                                <div class="grid gap-1">
                                    <div class="flex items-center gap-2.5">
                                        <p class="font-medium">{{ $passkey['name'] }}</p>
                                        @if ($passkey['authenticator'])
                                            <x-ui.badge>{{ $passkey['authenticator'] }}</x-ui.badge>
                                        @endif
                                    </div>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                        @if ($passkey['last_used_at_diff'])
                                            <span class="mx-1 opacity-50">/</span>
                                            {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <x-ui.button variant="ghost" size="sm" icon="trash" wire:click="confirmDelete({{ $passkey['id'] }})" class="text-red-600 dark:text-red-400" aria-label="{{ __('Remove passkey') }}" />
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-zinc-800">
                                <x-ui.icon name="key" class="size-7 text-zinc-400 dark:text-zinc-500" />
                            </div>
                            <p class="font-medium">{{ __('No passkeys yet') }}</p>
                            <x-ui.text muted class="mt-1">{{ __('Add a passkey to sign in without a password') }}</x-ui.text>
                        </div>
                    @endforelse
                </div>

                <x-passkey-registration />
            </section>
        @endif
    </x-pages::settings.layout>

    @if ($canManagePasskeys)
        <x-ui.modal name="delete-passkey-modal">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <x-ui.heading size="lg">{{ __('Remove passkey') }}</x-ui.heading>
                    <x-ui.text>{{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}</x-ui.text>
                </div>

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeDeleteModal">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button variant="danger" wire:click="deletePasskey">{{ __('Remove passkey') }}</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif
</section>
