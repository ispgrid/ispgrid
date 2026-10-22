<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Login log') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Every login attempt FreeRADIUS answered, with the real reason. Dropped means no answer was sent (a NAS that must send Message-Authenticator did not).') }}</x-ui.text>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-ui.input wire:model.live.debounce.300ms="username" type="search" :label="__('Username')" autocomplete="off" />
        <x-ui.select wire:model.live="result" :label="__('Result')" :options="$this->results" :placeholder="__('All results')" />
        <x-ui.input wire:model.live.debounce.300ms="reason" type="search" :label="__('Reason')" :placeholder="__('Part of the reason')" autocomplete="off" />
        <x-ui.select wire:model.live="router" :label="__('NAS')" :options="$this->routers" :placeholder="__('All NAS')" />
        <x-ui.input wire:model.live.debounce.300ms="ip" type="search" :label="__('IP')" :placeholder="__('Exact IP address')" autocomplete="off" />
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('When') }}</x-ui.th>
            <x-ui.th>{{ __('Username') }}</x-ui.th>
            <x-ui.th>{{ __('Result') }}</x-ui.th>
            <x-ui.th>{{ __('Reason') }}</x-ui.th>
            <x-ui.th>{{ __('NAS') }}</x-ui.th>
            <x-ui.th>{{ __('Device (MAC)') }}</x-ui.th>
            <x-ui.th>{{ __('IP') }}</x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="login-{{ $row['id'] }}">
                <x-ui.td class="tabular-nums whitespace-nowrap">{{ $row['when'] }}</x-ui.td>
                <x-ui.td>
                    @if ($row['account'])
                        <x-ui.link href="{{ route('customers.show', $row['account']) }}" wire:navigate class="font-medium">{{ $row['username'] }}</x-ui.link>
                    @else
                        <span class="font-medium">{{ $row['username'] }}</span>
                    @endif
                </x-ui.td>
                <x-ui.td><x-ui.badge :color="$row['color']">{{ $row['result'] }}</x-ui.badge></x-ui.td>
                <x-ui.td class="break-words">{{ $row['reason'] }}</x-ui.td>
                <x-ui.td>{{ $row['router'] }}</x-ui.td>
                <x-ui.td class="font-mono">{{ $row['mac'] }}</x-ui.td>
                <x-ui.td class="font-mono">{{ $row['ip'] }}</x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="7" class="py-8 text-center text-zinc-600 dark:text-zinc-400">{{ __('No login attempt matches these filters.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    {{ $this->logins->links() }}
</section>
