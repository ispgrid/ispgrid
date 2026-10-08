<x-ui.nav-group>
    <x-ui.nav-item :href="route('dashboard')" icon="home" :current="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-ui.nav-item>
</x-ui.nav-group>

<x-ui.nav-group :heading="__('System')">
</x-ui.nav-group>
