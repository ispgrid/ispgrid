<x-ui.nav-group>
    <x-ui.nav-item :href="route('dashboard')" icon="home" :current="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-ui.nav-item>
</x-ui.nav-group>

<x-ui.nav-group :heading="__('System')">
    <x-ui.nav-item :href="route('staff.index')" icon="users" :current="request()->routeIs('staff.*')">{{ __('Staff') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('app-settings.edit')" icon="cog-6-tooth" :current="request()->routeIs('app-settings.*')">{{ __('App settings') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('connection-types.index')" icon="wifi" :current="request()->routeIs('connection-types.*')">{{ __('Connection types') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('activity.index')" icon="clipboard-document-list" :current="request()->routeIs('activity.*')">{{ __('Activity log') }}</x-ui.nav-item>
</x-ui.nav-group>
