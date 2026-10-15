<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Customers') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Everyone who logs in through your NAS. FreeRADIUS knows each customer by their username.') }}</x-ui.text>
        </div>

        @can('create', \App\Models\Account::class)
            <x-ui.button icon="plus" :href="route('customers.create')" wire:navigate>{{ __('Add customer') }}</x-ui.button>
        @endcan
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.input wire:model.live.debounce.300ms="search" type="search" :label="__('Search')" :placeholder="__('Username, name, mobile, email or IP')" autocomplete="off" />
        <x-ui.select wire:model.live="status" :label="__('Status')" :options="$this->statuses" />
        <x-ui.select wire:model.live="plan" :label="__('Plan')" :options="$this->plans" :placeholder="__('All plans')" />
        <x-ui.select wire:model.live="type" :label="__('Type')" :options="$this->types" :placeholder="__('All types')" />
        <x-ui.select wire:model.live="router" :label="__('NAS')" :options="$this->routers" :placeholder="__('All NAS')" />
        <x-ui.select wire:model.live="connection" :label="__('Connection type')" :options="$this->connectionTypes" :placeholder="__('All connection types')" />
        <div class="flex items-end pb-2">
            <x-ui.checkbox wire:model.live="expiring" :label="__('Expiring within 7 days')" />
        </div>
        <div class="flex items-end pb-2">
            <x-ui.checkbox wire:model.live="expired" :label="__('Expired')" />
        </div>
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th>{{ __('Type') }}</x-ui.th>
            <x-ui.th>{{ __('Plan') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Expiry') }}</x-ui.th>
            <x-ui.th>{{ __('NAS') }}</x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="account-{{ $row['id'] }}">
                <x-ui.td>
                    <x-ui.link href="{{ route('customers.show', $row['id']) }}" wire:navigate class="font-medium">{{ $row['username'] }}</x-ui.link>
                    @if ($row['trashed'])
                        <x-ui.badge class="ms-2">{{ __('Deleted') }}</x-ui.badge>
                    @endif
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $row['fullname'] }}</span>
                </x-ui.td>
                <x-ui.td>{{ $row['type'] }}</x-ui.td>
                <x-ui.td>{{ $row['plan'] ?? __('None') }}</x-ui.td>
                <x-ui.td><x-ui.badge :color="$row['color']">{{ $row['status'] }}</x-ui.badge></x-ui.td>
                <x-ui.td class="tabular-nums whitespace-nowrap">
                    @if ($row['pending'])
                        {{ __('Not started') }}
                    @elseif ($row['expiry'] === null)
                        {{ __('Never') }}
                    @else
                        {{ $row['expiry'] }}
                        @if ($row['expired'])
                            <x-ui.badge color="red" class="ms-1">{{ __('Expired') }}</x-ui.badge>
                        @endif
                    @endif
                </x-ui.td>
                <x-ui.td>{{ $row['router'] ?? __('Any NAS') }}</x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="6" class="text-center text-zinc-500">{{ __('No customers match.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    {{ $this->accounts->links() }}
</section>
