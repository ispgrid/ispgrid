<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-5">
            @csrf
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <x-ui.input name="email" type="email" :label="__('Email')" :value="request('email')" required autocomplete="email" />

            <x-ui.input
                name="password"
                type="password"
                :label="__('Password')"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <x-ui.input
                name="password_confirmation"
                type="password"
                :label="__('Confirm password')"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <x-ui.button type="submit" class="w-full" data-test="reset-password-button">{{ __('Reset password') }}</x-ui.button>
        </form>
    </div>
</x-layouts::auth>
