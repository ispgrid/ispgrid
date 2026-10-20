<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-ui.heading level="1" size="xl">{{ __('Notification rules') }}</x-ui.heading>
            <x-ui.text muted class="mt-1">{{ __('Each rule sends one approved template on one event. A new rule starts switched off: send a test first.') }}</x-ui.text>
        </div>
        <x-ui.button :href="route('notification-rules.create')" wire:navigate>{{ __('Add rule') }}</x-ui.button>
    </div>

    <x-ui.card>
        @if ($this->rules === [])
            <x-ui.text muted>{{ __('No rules yet.') }}</x-ui.text>
        @else
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th>{{ __('Name') }}</x-ui.th>
                    <x-ui.th>{{ __('When') }}</x-ui.th>
                    <x-ui.th>{{ __('Channel') }}</x-ui.th>
                    <x-ui.th>{{ __('Sent in 30 days') }}</x-ui.th>
                    <x-ui.th>{{ __('On') }}</x-ui.th>
                    <x-ui.th></x-ui.th>
                </x-slot:head>
                @foreach ($this->rules as $rule)
                    <tr wire:key="rule-{{ $rule['id'] }}">
                        <x-ui.td>
                            <x-ui.link href="{{ route('notification-rules.edit', $rule['id']) }}" wire:navigate class="font-medium">{{ $rule['name'] }}</x-ui.link>
                            <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ $rule['provider'] }}</span>
                        </x-ui.td>
                        <x-ui.td>{{ $rule['trigger'] }}</x-ui.td>
                        <x-ui.td>{{ $rule['channel'] }}</x-ui.td>
                        <x-ui.td class="tabular-nums">{{ $rule['sent'] }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.button size="sm" :variant="$rule['enabled'] ? 'secondary' : 'ghost'" wire:click="toggle({{ $rule['id'] }})">
                                {{ $rule['enabled'] ? __('On') : __('Off') }}
                            </x-ui.button>
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <x-ui.button size="sm" variant="danger" wire:click="delete({{ $rule['id'] }})" wire:confirm="{{ __('Delete this rule?') }}">{{ __('Delete') }}</x-ui.button>
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
</section>
