<div class="grid grid-cols-1 gap-5">
    <div>
        <x-ui.heading size="lg">{{ $this->mustChange ? __('Choose your own password') : __('Change selfcare password') }}</x-ui.heading>
        @if ($this->mustChange)
            <x-ui.text muted class="mt-1">{{ __('The password you were given works only once. Choose your own to continue.') }}</x-ui.text>
        @endif
    </div>

    <form wire:submit="save" class="grid grid-cols-1 gap-4" novalidate>
        @unless ($this->mustChange)
            <x-ui.input wire:model="current_password" type="password" :label="__('Current password')" autocomplete="current-password" required viewable />
        @endunless
        <x-ui.input wire:model="password" type="password" :label="__('New password')" autocomplete="new-password" required viewable />
        <x-ui.input wire:model="password_confirmation" type="password" :label="__('Confirm new password')" autocomplete="new-password" required viewable />
        <x-ui.button type="submit" class="w-full">{{ __('Save password') }}</x-ui.button>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-3">
        @unless ($this->mustChange)
            <x-ui.link :href="route('selfcare.dashboard')">{{ __('Back') }}</x-ui.link>
        @endunless
        <form method="POST" action="{{ route('selfcare.logout') }}">
            @csrf
            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Log out') }}</x-ui.button>
        </form>
    </div>
</div>
