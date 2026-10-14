<x-ui.nav-group>
    <x-ui.nav-item :href="route('dashboard')" icon="home" :current="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-ui.nav-item>
    @can('viewAny', \App\Models\Account::class)
        <x-ui.nav-item :href="route('customers.index')" icon="user-group" :current="request()->routeIs('customers.*')">{{ __('Customers') }}</x-ui.nav-item>
    @endcan
    @can('view-online-sessions')
        <x-ui.nav-item :href="route('online.index')" icon="signal" :current="request()->routeIs('online.*')">{{ __('Online') }}</x-ui.nav-item>
    @endcan
    @can('view-login-log')
        <x-ui.nav-item :href="route('login-log.index')" icon="queue-list" :current="request()->routeIs('login-log.*')">{{ __('Login log') }}</x-ui.nav-item>
    @endcan
    @can('viewAny', \App\Models\Plan::class)
        <x-ui.nav-item :href="route('plans.index')" icon="rectangle-stack" :current="request()->routeIs('plans.*')">{{ __('Plans') }}</x-ui.nav-item>
    @endcan
</x-ui.nav-group>

@can('view-network')
    <x-ui.nav-group name="network" :heading="__('Network')" collapsible :active="request()->routeIs('routers.*', 'pools.*', 'ip-ranges.*', 'address-lists.*')">
        <x-ui.nav-item :href="route('routers.index')" icon="server-stack" :current="request()->routeIs('routers.*')">{{ __('NAS') }}</x-ui.nav-item>
        <x-ui.nav-item :href="route('pools.index')" icon="circle-stack" :current="request()->routeIs('pools.*')">{{ __('Pools') }}</x-ui.nav-item>
        <x-ui.nav-item :href="route('ip-ranges.index')" icon="globe-alt" :current="request()->routeIs('ip-ranges.*')">{{ __('IP ranges') }}</x-ui.nav-item>
        <x-ui.nav-item :href="route('address-lists.index')" icon="list-bullet" :current="request()->routeIs('address-lists.*')">{{ __('Address lists') }}</x-ui.nav-item>
    </x-ui.nav-group>
@endcan

@canany(['manage-staff', 'manage-app-settings', 'manage-radius-settings', 'manage-connection-types', 'view-activity-log', 'import-data'])
    <x-ui.nav-group name="system" :heading="__('System')" collapsible :active="request()->routeIs('staff.*', 'roles.*', 'app-settings.*', 'radius-settings.*', 'connection-types.*', 'activity.*', 'import.*')">
        @can('manage-staff')
            <x-ui.nav-item :href="route('staff.index')" icon="users" :current="request()->routeIs('staff.*')">{{ __('Staff') }}</x-ui.nav-item>
            <x-ui.nav-item :href="route('roles.index')" icon="key" :current="request()->routeIs('roles.*')">{{ __('Roles') }}</x-ui.nav-item>
        @endcan
        @can('manage-app-settings')
            <x-ui.nav-item :href="route('app-settings.edit')" icon="cog-6-tooth" :current="request()->routeIs('app-settings.*')">{{ __('App settings') }}</x-ui.nav-item>
        @endcan
        @can('manage-radius-settings')
            <x-ui.nav-item :href="route('radius-settings.edit')" icon="adjustments-horizontal" :current="request()->routeIs('radius-settings.*')">{{ __('FreeRADIUS') }}</x-ui.nav-item>
        @endcan
        @can('manage-connection-types')
            <x-ui.nav-item :href="route('connection-types.index')" icon="wifi" :current="request()->routeIs('connection-types.*')">{{ __('Connection types') }}</x-ui.nav-item>
        @endcan
        @can('view-activity-log')
            <x-ui.nav-item :href="route('activity.index')" icon="clipboard-document-list" :current="request()->routeIs('activity.*')">{{ __('Activity log') }}</x-ui.nav-item>
        @endcan
        @can('import-data')
            <x-ui.nav-item :href="route('import.index')" icon="arrow-up-tray" :current="request()->routeIs('import.*')">{{ __('Import') }}</x-ui.nav-item>
        @endcan
    </x-ui.nav-group>
@endcanany
