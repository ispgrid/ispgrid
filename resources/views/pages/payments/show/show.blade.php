<section class="grid gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.heading level="1" size="xl">{{ __('Receipt') }} {{ $this->printed['number'] }}</x-ui.heading>
                @if ($this->printed['removed'])
                    <x-ui.badge color="zinc">{{ __('Removed') }}</x-ui.badge>
                @endif
            </div>
            <x-ui.text muted class="mt-1">
                <x-ui.link href="{{ route('customers.show', ['account' => $this->accountId, 'tab' => 'billing']) }}" wire:navigate>{{ $this->printed['customer']['billing_lines'][0] ?? $this->printed['customer']['username'] }}</x-ui.link>
                ({{ $this->printed['customer']['username'] }})
            </x-ui.text>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="secondary" href="{{ route('payments.receipt', $this->paymentId) }}" icon="arrow-down-tray">{{ __('Download PDF') }}</x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('payments.receipt', ['payment' => $this->paymentId, 'print' => 1]) }}" target="_blank" rel="noopener">{{ __('Print') }}</x-ui.button>
            @if ($this->canEdit)
                <x-ui.button variant="secondary" wire:click="openEditPayment">{{ __('Edit') }}</x-ui.button>
            @endif
        </div>
    </div>

    @if ($this->note)
        <x-ui.alert>{{ __('Note (staff only): :note', ['note' => $this->note]) }}</x-ui.alert>
    @endif

    @if ($this->printed['removed'])
        <x-ui.alert variant="danger">{{ __('Removed: :reason', ['reason' => $this->printed['remove_reason']]) }}</x-ui.alert>
    @endif

    <x-ui.card class="grid gap-6">
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="text-sm">
                <p class="font-semibold">{{ $this->printed['company']['name'] }}</p>
                @foreach ($this->printed['company']['lines'] as $line)
                    <p>{{ $line }}</p>
                @endforeach
                @if ($this->printed['company']['tax'])
                    <p>{{ $this->printed['company']['tax'] }}</p>
                @endif
            </div>
            <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1 text-sm">
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Received at') }}</dt><dd>{{ $this->printed['date'] }}</dd>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Method') }}</dt><dd>{{ $this->printed['method'] }}</dd>
                @if ($this->printed['reference'])
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Reference') }}</dt><dd class="break-words">{{ $this->printed['reference'] }}</dd>
                @endif
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Invoice') }}</dt>
                <dd><x-ui.link href="{{ route('invoices.show', $this->invoiceId) }}" wire:navigate>{{ $this->printed['invoice_number'] }}</x-ui.link> ({{ $this->printed['invoice_date'] }})</dd>
            </dl>
        </div>

        <div class="grid gap-6 sm:grid-cols-2 text-sm">
            <div>
                <p class="text-xs uppercase text-zinc-600 dark:text-zinc-400">{{ __('Received from') }}</p>
                @foreach ($this->printed['customer']['billing_lines'] as $line)
                    <p @class(['font-semibold' => $loop->first])>{{ $line }}</p>
                @endforeach
                @if ($this->printed['customer']['tax_number'])
                    <p>{{ $this->printed['customer']['tax_label'] }}: {{ $this->printed['customer']['tax_number'] }}</p>
                @endif
            </div>
            <div>
                <p class="text-xs uppercase text-zinc-600 dark:text-zinc-400">{{ __('Amount received') }}</p>
                <p class="text-2xl font-semibold tabular-nums">{{ $this->printed['amount'] }}</p>
                <p>{{ $this->printed['amount_in_words'] }}</p>
            </div>
        </div>

        <dl class="grid gap-1 text-sm sm:ms-auto sm:w-1/2">
            <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('Invoice total') }}</dt><dd class="tabular-nums">{{ $this->printed['invoice_total'] }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-zinc-600 dark:text-zinc-400">{{ __('This payment') }}</dt><dd class="tabular-nums">{{ $this->printed['amount'] }}</dd></div>
            @if ($this->printed['balance_after'] !== null)
                <div class="mt-1 flex justify-between gap-4 border-t border-zinc-200 pt-2 font-semibold dark:border-zinc-700"><dt>{{ __('Balance due after this payment') }}</dt><dd class="tabular-nums">{{ $this->printed['balance_after'] }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    @if ($this->canEdit)
        <x-ui.modal name="edit-payment">
            <form wire:submit="editPayment" class="grid gap-5" novalidate>
                <x-ui.heading size="lg">{{ __('Edit payment') }}</x-ui.heading>
                <x-ui.text>{{ __('The amount and the invoice stay as they are. For a wrong amount, remove the payment and record it again.') }}</x-ui.text>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.select wire:model="edit_method_id" :label="__('Method')" :options="$this->editMethods" />
                    <x-ui.input wire:model="edit_paid_at" type="datetime-local" :label="__('Received at')" />
                    <div class="sm:col-span-2">
                        <x-ui.input wire:model="edit_reference" :label="__('Reference')" maxlength="100" :description="__('UPI transaction ID or cheque number.')" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-ui.input wire:model="edit_note" :label="__('Note')" maxlength="255" :description="__('For staff only. It is not printed on the receipt.')" />
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'edit-payment' })">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</section>
