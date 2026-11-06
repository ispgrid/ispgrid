<div class="grid grid-cols-1 gap-5">
    <div>
        <x-ui.heading size="lg">{{ __('Change PPPoE password') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('The password your router uses to get online. Your selfcare password stays the same.') }}</x-ui.text>
    </div>

    <form wire:submit="save" class="grid grid-cols-1 gap-4" novalidate>
        <x-ui.input wire:model="selfcare_password" type="password" :label="__('Selfcare password')" autocomplete="current-password" required viewable />
        <x-ui.input wire:model="password" :label="__('New PPPoE password')" autocomplete="off" maxlength="64" english required />
        <x-ui.input wire:model="password_confirmation" :label="__('New PPPoE password again')" autocomplete="off" maxlength="64" english required />
        <x-ui.alert>{{ __('You will be disconnected now. Put the new password in your router, or you will stay offline.') }}</x-ui.alert>
        <x-ui.button type="submit" class="w-full">{{ __('Change PPPoE password') }}</x-ui.button>
    </form>

    <x-ui.link :href="route('selfcare.dashboard')">{{ __('Back') }}</x-ui.link>
</div>
