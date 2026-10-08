<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5">
            @csrf

            <x-ui.input name="email" type="email" :label="__('Email address')" required autofocus placeholder="email@example.com" />

            <x-ui.button type="submit" class="w-full" data-test="email-password-reset-link-button">{{ __('Email password reset link') }}</x-ui.button>
        </form>

        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('Or, return to') }}
            <x-ui.link :href="route('login')" wire:navigate>{{ __('log in') }}</x-ui.link>
        </p>
    </div>
</x-layouts::auth>
