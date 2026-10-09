<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Dashboard') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Customer and session figures appear here once customers are added.') }}</x-ui.text>
    </div>

    @foreach ($this->outdatedRouters as $outdated)
        <x-ui.alert variant="warning" wire:key="outdated-{{ $outdated['id'] }}">
            <span class="font-medium">{{ $outdated['router'] }}</span>
            <span>{{ $outdated['warning'] }}</span>
            <x-ui.link href="{{ route('routers.index') }}" wire:navigate>{{ __('Open routers') }}</x-ui.link>
        </x-ui.alert>
    @endforeach
</section>
