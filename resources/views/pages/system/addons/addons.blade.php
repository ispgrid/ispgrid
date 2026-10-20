<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Add-ons') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Add-ons are installed and removed on the server with sudo ispgrid addon. Each one keeps its settings here.') }}</x-ui.text>
    </div>

    @forelse ($this->addons as $addon)
        <x-ui.card class="grid content-start gap-5" wire:key="addon-{{ $addon['key'] }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <x-ui.heading>{{ $addon['name'] }}</x-ui.heading>
                    <x-ui.text muted class="font-mono text-xs">{{ $addon['package'] }} {{ $addon['version'] }}</x-ui.text>
                    @if ($addon['description'] !== '')
                        <x-ui.text muted class="mt-1">{{ $addon['description'] }}</x-ui.text>
                    @endif
                </div>
                <x-ui.badge :color="$addon['color']">{{ $addon['status'] }}</x-ui.badge>
            </div>

            @if ($addon['settings'] !== null)
                <livewire:dynamic-component :is="$addon['settings']" :key="'addon-settings-'.$addon['key']" />
            @endif
        </x-ui.card>
    @empty
        <x-ui.card>
            <x-ui.text muted>{{ __('No add-ons installed.') }}</x-ui.text>
        </x-ui.card>
    @endforelse
</section>
