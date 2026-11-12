<div class="grid grid-cols-1 gap-5">
    <div>
        <x-ui.heading size="lg">{{ __('Log in to Selfcare') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('See your plan, renew, and download your bills.') }}</x-ui.text>
    </div>

    @if (session('selfcare_message'))
        <x-ui.alert>{{ session('selfcare_message') }}</x-ui.alert>
    @endif

    <form wire:submit="login" class="grid grid-cols-1 gap-4" novalidate>
        <x-ui.input wire:model="username" :label="__('Username, account ID or mobile number')" maxlength="64" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus />
        <x-ui.input wire:model="password" type="password" :label="__('Selfcare password')" autocomplete="current-password" required viewable />
        <x-ui.button type="submit" class="w-full">{{ __('Log in') }}</x-ui.button>
    </form>

    <x-ui.text muted class="text-sm">{{ __('No password yet, or forgot it? Call us and we will set one for you.') }}</x-ui.text>
</div>
