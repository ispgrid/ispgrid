<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="grid gap-5">
            <div class="grid gap-5 lg:grid-cols-2">
                <x-ui.input wire:model="name" :label="__('Name')" required autofocus autocomplete="name" />
                <x-ui.input wire:model="email" type="email" :label="__('Email')" required autocomplete="email" />
            </div>

            <div>
                <x-ui.button type="submit" data-test="update-profile-button">{{ __('Save') }}</x-ui.button>
            </div>
        </form>

        <form wire:submit="updateLanguage" class="mt-10 grid gap-5">
            <x-ui.heading>{{ __('Language') }}</x-ui.heading>
            <x-ui.select
                wire:model="locale"
                :label="__('Your panel language')"
                :options="$this->languages"
                :placeholder="__('Panel default (:language)', ['language' => $this->panelLanguage])"
                :description="__('The panel default is set in App settings.')"
            />

            <div>
                <x-ui.button type="submit">{{ __('Save language') }}</x-ui.button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
