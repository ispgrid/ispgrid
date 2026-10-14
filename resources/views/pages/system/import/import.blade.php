<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Import') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Add plans and customers from CSV files made from the templates. Check a file first: nothing is saved until every row is right, and then all rows are saved together.') }}</x-ui.text>
    </div>

    @foreach ($this->cards as $card)
        <x-ui.card wire:key="import-{{ $card['kind'] }}" class="grid gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 sm:flex-1">
                    <x-ui.heading>{{ $card['title'] }}</x-ui.heading>
                    <x-ui.text muted class="mt-1">{{ $card['description'] }}</x-ui.text>
                </div>
                <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray" wire:click="downloadTemplate('{{ $card['kind'] }}')">{{ __('Download template') }}</x-ui.button>
            </div>

            <form wire:submit="check('{{ $card['kind'] }}')" class="flex flex-wrap items-end gap-3">
                <div class="min-w-60 flex-1">
                    <x-ui.input type="file" wire:model="{{ $card['file'] }}" :label="__('CSV file')" accept=".csv,text/csv" />
                </div>
                <x-ui.button type="submit" variant="secondary">{{ __('Check file') }}</x-ui.button>
            </form>

            @if ($card['ready'] !== null)
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.alert variant="success" class="flex-1">{{ $card['readyText'] }}</x-ui.alert>
                    <x-ui.button icon="arrow-up-tray" wire:click="import('{{ $card['kind'] }}')" wire:loading.attr="disabled">{{ $card['importText'] }}</x-ui.button>
                </div>
            @endif

            @if ($card['problems'] !== [])
                <x-ui.alert variant="danger">{{ $card['problemsText'] }}</x-ui.alert>
                <x-ui.table>
                    <x-slot:head>
                        <x-ui.th>{{ __('Row') }}</x-ui.th>
                        <x-ui.th>{{ __('Column') }}</x-ui.th>
                        <x-ui.th>{{ __('Problem') }}</x-ui.th>
                    </x-slot:head>
                    @foreach ($card['problems'] as $problem)
                        <tr wire:key="{{ $card['kind'] }}-problem-{{ $loop->index }}">
                            <x-ui.td class="tabular-nums">{{ $problem['row'] }}</x-ui.td>
                            <x-ui.td>{{ $problem['column'] }}</x-ui.td>
                            <x-ui.td>{{ $problem['message'] }}</x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    @endforeach
</section>
