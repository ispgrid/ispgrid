@foreach ($menu as $group)
    <x-ui.nav-group :name="$group['collapsible'] ? $group['name'] : null" :heading="$group['heading']" :collapsible="$group['collapsible']" :active="$group['active']">
        @foreach ($group['items'] as $item)
            <x-ui.nav-item :href="$item['href']" :icon="$item['icon']" :current="$item['current']">{{ $item['label'] }}</x-ui.nav-item>
        @endforeach
    </x-ui.nav-group>
@endforeach
