<div class="flex items-start gap-10 max-md:flex-col max-md:gap-6">
    <nav class="grid w-full gap-0.5 md:w-56" aria-label="{{ __('Settings') }}">
        <x-ui.nav-item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')">{{ __('Profile') }}</x-ui.nav-item>
        <x-ui.nav-item :href="route('security.edit')" :current="request()->routeIs('security.edit')">{{ __('Security') }}</x-ui.nav-item>
        <x-ui.nav-item :href="route('appearance.edit')" :current="request()->routeIs('appearance.edit')">{{ __('Appearance') }}</x-ui.nav-item>
    </nav>

    <div class="w-full max-w-lg flex-1">
        <x-ui.heading>{{ $heading ?? '' }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ $subheading ?? '' }}</x-ui.text>

        <div class="mt-6">{{ $slot }}</div>
    </div>
</div>
