<?php

use App\Billing\BillingSettings;
use App\Billing\Pdf\InvoiceView;
use App\Enums\InvoiceStatus;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ManagesInvoices;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Concerns\TakesPayment;
use App\Livewire\Concerns\WritesInvoices;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One invoice as it prints, with Download PDF, Print, Record payment, Remove payment, Cancel and Cancel and reissue
 * (the Billing tab's dialogs and permissions).
 */
new #[Title('Invoice')] class extends Component
{
    use AuthorizesEveryRequest, ManagesInvoices, ShowsToasts, TakesPayment, WritesInvoices;

    #[Locked]
    public int $invoiceId;

    #[Locked]
    public int $accountId;

    public function mount(int $invoice): void
    {
        $this->authorizeAccess();
        $found = Invoice::query()->findOrFail($invoice);
        $this->invoiceId = $found->id;
        $this->accountId = $found->account_id;
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('view-invoices'), 403);
    }

    #[Computed]
    public function invoice(): Invoice
    {
        return Invoice::query()->with(['lines', 'taxLines', 'account', 'replaces', 'replacedBy'])->findOrFail($this->invoiceId);
    }

    #[Computed]
    public function account(): Account
    {
        return Account::withTrashed()->findOrFail($this->accountId);
    }

    /**
     * The invoice this one replaced and the one that replaced it (Cancel and reissue).
     *
     * @return array{replaces: ?array{id: int, number: string}, replaced_by: ?array{id: int, number: string}}
     */
    #[Computed]
    public function related(): array
    {
        $link = fn (?Invoice $invoice): ?array => $invoice === null ? null : ['id' => $invoice->id, 'number' => $invoice->number];

        return ['replaces' => $link($this->invoice->replaces), 'replaced_by' => $link($this->invoice->replacedBy)];
    }

    /**
     * Every string the invoice prints (the same as its PDF).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function printed(): array
    {
        return InvoiceView::for($this->invoice);
    }

    /**
     * The status badge.
     *
     * @return array{label: string, color: string}
     */
    #[Computed]
    public function status(): array
    {
        return ['label' => $this->invoice->status->label(), 'color' => $this->invoice->status->color()];
    }

    /**
     * What staff may do: nothing on a deleted customer's invoice, and no new payment while invoicing is off.
     *
     * @return array{record: bool, remove: bool, cancel: bool, reissue: bool, open: bool, cancelled: bool}
     */
    #[Computed]
    public function can(): array
    {
        $live = ! $this->invoice->account->trashed();
        $open = in_array($this->invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartlyPaid], true);

        return [
            'record' => $live && Gate::allows('record-payments') && BillingSettings::current()->enabled,
            'remove' => $live && Gate::allows('record-payments'),
            'cancel' => $live && Gate::allows('cancel-invoices'),
            'reissue' => $live && Gate::allows('cancel-invoices') && Gate::allows('create-invoices') && BillingSettings::current()->canIssue(),
            'open' => $open,
            'cancelled' => $this->invoice->status === InvoiceStatus::Cancelled,
        ];
    }

    /**
     * @return list<array{id: int, date: string, method: string, reference: string, amount: string, staff: string, removed: bool, reason: ?string}>
     */
    #[Computed]
    public function payments(): array
    {
        $money = Money::fromSettings();
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';

        return Payment::withTrashed()->where('invoice_id', $this->invoiceId)->with(['method', 'user'])->oldest('paid_at')->oldest('id')->get()
            ->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'date' => $payment->paid_at->format($format),
                'method' => (string) $payment->method?->name,
                'reference' => (string) $payment->reference,
                'amount' => $money->format((string) $payment->amount),
                'staff' => $payment->user?->name ?? __('System'),
                'removed' => $payment->trashed(),
                'reason' => $payment->remove_reason,
            ])->all();
    }

    protected function invoiceReissued(Invoice $invoice, string $message): void
    {
        $this->redirectRoute('invoices.show', $invoice->id, navigate: true);
    }

    protected function invoiceChanged(string $dialog, string $message): void
    {
        unset($this->invoice, $this->printed, $this->status, $this->can, $this->payments);
        $this->dispatch('modal-close', name: $dialog);
        $this->toast($message);
    }
};
