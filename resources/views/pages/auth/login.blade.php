<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            <x-ui.input name="email" type="email" :label="__('Email address')" :value="old('email')" required autofocus autocomplete="email" placeholder="email@example.com" />

            <div class="grid gap-1.5">
                <x-ui.input name="password" type="password" :label="__('Password')" required autocomplete="current-password" viewable />

                @if (Route::has('password.request'))
                    <x-ui.link class="justify-self-end text-sm" :href="route('password.request')" wire:navigate>{{ __('Forgot your password?') }}</x-ui.link>
                @endif
            </div>

            <x-ui.checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <x-ui.button type="submit" class="w-full" data-test="login-button">{{ __('Log in') }}</x-ui.button>
        </form>
    </div>
</x-layouts::auth>
