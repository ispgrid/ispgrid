<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Activity log') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Who changed what, newest first.') }}</x-ui.text>
    </div>

    <div class="flex flex-wrap gap-4">
        <div class="w-56">
            <x-ui.select wire:model.live="staff" :label="__('Staff')" :placeholder="__('Everyone')" :options="$this->staffOptions" />
        </div>
        <div class="w-56">
            <x-ui.select wire:model.live="type" :label="__('Item')" :placeholder="__('Everything')" :options="$this->typeOptions" />
        </div>
    </div>

    <x-ui.table class="[&_td]:align-top">
        <x-slot:head>
            <x-ui.th>{{ __('When') }}</x-ui.th>
            <x-ui.th>{{ __('Staff') }}</x-ui.th>
            <x-ui.th>{{ __('Action') }}</x-ui.th>
            <x-ui.th>{{ __('Item') }}</x-ui.th>
            <x-ui.th>{{ __('Changes') }}</x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="activity-{{ $row['id'] }}">
                <x-ui.td class="whitespace-nowrap tabular-nums">{{ $row['when'] }}</x-ui.td>
                <x-ui.td>{{ $row['staff'] }}</x-ui.td>
                <x-ui.td>{{ $row['event'] }}</x-ui.td>
                <x-ui.td>
                    <span class="text-zinc-600 dark:text-zinc-400">{{ $row['type'] }}</span>
                    <span class="font-medium">{{ $row['name'] }}</span>
                </x-ui.td>
                <x-ui.td>
                    <dl class="grid gap-0.5 text-xs">
                        @foreach ($row['changes'] as $change)
                            <div class="flex flex-wrap gap-1">
                                <dt class="text-zinc-600 dark:text-zinc-400">{{ $change['field'] }}:</dt>
                                <dd>
                                    @if ($change['old'] !== null)
                                        <span class="line-through decoration-zinc-400">{{ $change['old'] }}</span>
                                        <span aria-hidden="true">&rarr;</span>
                                    @endif
                                    <span>{{ $change['new'] ?? __('(empty)') }}</span>
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="5" class="py-8 text-center text-zinc-600 dark:text-zinc-400">{{ __('No activity matches these filters.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    {{ $this->activities->links() }}
</section>
