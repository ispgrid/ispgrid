<x-ui.nav-group>
    <x-ui.nav-item :href="route('dashboard')" icon="home" :current="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-ui.nav-item>
</x-ui.nav-group>

<x-ui.nav-group :heading="__('Network')">
    <x-ui.nav-item :href="route('routers.index')" icon="server-stack" :current="request()->routeIs('routers.*')">{{ __('Routers') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('pools.index')" icon="circle-stack" :current="request()->routeIs('pools.*')">{{ __('Pools') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('ip-ranges.index')" icon="globe-alt" :current="request()->routeIs('ip-ranges.*')">{{ __('IP ranges') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('address-lists.index')" icon="list-bullet" :current="request()->routeIs('address-lists.*')">{{ __('Address lists') }}</x-ui.nav-item>
</x-ui.nav-group>

<x-ui.nav-group :heading="__('System')">
    <x-ui.nav-item :href="route('staff.index')" icon="users" :current="request()->routeIs('staff.*')">{{ __('Staff') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('app-settings.edit')" icon="cog-6-tooth" :current="request()->routeIs('app-settings.*')">{{ __('App settings') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('radius-settings.edit')" icon="adjustments-horizontal" :current="request()->routeIs('radius-settings.*')">{{ __('FreeRADIUS') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('connection-types.index')" icon="wifi" :current="request()->routeIs('connection-types.*')">{{ __('Connection types') }}</x-ui.nav-item>
    <x-ui.nav-item :href="route('activity.index')" icon="clipboard-document-list" :current="request()->routeIs('activity.*')">{{ __('Activity log') }}</x-ui.nav-item>
</x-ui.nav-group>
