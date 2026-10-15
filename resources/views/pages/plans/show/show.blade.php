<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.link href="{{ route('plans.index') }}" wire:navigate class="text-sm">{{ __('Plans') }}</x-ui.link>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <x-ui.heading level="1" size="xl">{{ $this->plan->name }}</x-ui.heading>
                @if ($this->plan->trashed())
                    <x-ui.badge>{{ __('Deleted') }}</x-ui.badge>
                @endif
            </div>
            <x-ui.text muted class="mt-1">{{ trans_choice(':count customer|:count customers', $this->customerCount) }}</x-ui.text>
        </div>

        @unless ($this->plan->trashed())
            @can('update', $this->plan)
                <x-ui.button variant="secondary" :href="route('plans.edit', $this->plan->id)" wire:navigate>{{ __('Edit') }}</x-ui.button>
            @endcan
        @endunless
    </div>

    <div class="grid items-start gap-6 md:grid-cols-2">
        @foreach ($this->details as $section => $rows)
            <x-ui.card wire:key="section-{{ $loop->index }}">
                <x-ui.heading>{{ $section }}</x-ui.heading>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
                    @foreach ($rows as $label => $value)
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="font-medium break-words">{{ $value }}</dd>
                    @endforeach
                </dl>
            </x-ui.card>
        @endforeach
    </div>

    @if ($this->canSeeCustomers)
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <x-ui.heading>{{ __('Customers on this plan') }}</x-ui.heading>
                @if ($this->customerCount > 0)
                    <x-ui.link href="{{ route('customers.index', ['plan' => $this->plan->id]) }}" wire:navigate class="text-sm">{{ __('Filter them in Customers') }}</x-ui.link>
                @endif
            </div>

            @if ($this->rows === [])
                <x-ui.text muted class="mt-4">{{ __('No customer is on this plan.') }}</x-ui.text>
            @else
                <x-ui.table class="mt-4">
                    <x-slot:head>
                        <x-ui.th>{{ __('Customer') }}</x-ui.th>
                        <x-ui.th>{{ __('Type') }}</x-ui.th>
                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                        <x-ui.th>{{ __('Expiry') }}</x-ui.th>
                    </x-slot:head>
                    @foreach ($this->rows as $row)
                        <tr wire:key="account-{{ $row['id'] }}">
                            <x-ui.td>
                                <x-ui.link href="{{ route('customers.show', $row['id']) }}" wire:navigate class="font-medium">{{ $row['username'] }}</x-ui.link>
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $row['fullname'] }}</span>
                            </x-ui.td>
                            <x-ui.td>{{ $row['type'] }}</x-ui.td>
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
                        </tr>
                    @endforeach
                </x-ui.table>

                <div class="mt-4">{{ $this->accounts->links() }}</div>
            @endif
        </x-ui.card>
    @endif
</section>
