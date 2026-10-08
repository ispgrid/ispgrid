<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="grid gap-5">
            <x-ui.input wire:model="name" :label="__('Name')" required autofocus autocomplete="name" />
            <x-ui.input wire:model="email" type="email" :label="__('Email')" required autocomplete="email" />

            <div>
                <x-ui.button type="submit" data-test="update-profile-button">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
