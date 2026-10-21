<?php

use App\Billing\BillingSettings;
use App\Billing\Statement;
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
use Livewire\Component;

/**
 * The customer's Billing tab: dues, invoices, payments and the statement, with New invoice and Cancel and reissue
 * (WritesInvoices), and Record payment, Remove payment and Cancel invoice (ManagesInvoices). Read-only for a deleted
 * customer.
 */
new class extends Component
{
    use AuthorizesEveryRequest, ManagesInvoices, ShowsToasts, TakesPayment, WritesInvoices;

    #[Locked]
    public int $accountId;

    public function mount(int $accountId): void
    {
        $this->accountId = $accountId;
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('view-invoices'), 403);
    }

    #[Computed]
    public function account(): Account
    {
        return Account::withTrashed()->findOrFail($this->accountId);
    }

    /**
     * What staff may do here: nothing on a deleted customer, and no new invoice or payment while invoicing is off.
     *
     * @return array{create: bool, record: bool, remove: bool, cancel: bool, reissue: bool}
     */
    #[Computed]
    public function can(): array
    {
        $live = ! $this->account->trashed();
        $settings = BillingSettings::current();

        return [
            'create' => $live && Gate::allows('create-invoices') && $settings->canIssue(),
            'record' => $live && Gate::allows('record-payments') && $settings->enabled,
            'remove' => $live && Gate::allows('record-payments'),
            'cancel' => $live && Gate::allows('cancel-invoices'),
            'reissue' => $live && Gate::allows('cancel-invoices') && Gate::allows('create-invoices') && $settings->canIssue(),
        ];
    }

    #[Computed]
    public function dues(): string
    {
        return Money::fromSettings()->format(Statement::dues($this->account));
    }

    /**
     * @return list<array{id: int, number: string, date: string, for: string, total: string, paid: string, balance: string, status: string, color: string, open: bool, cancelled: bool, reason: ?string}>
     */
    #[Computed]
    public function invoices(): array
    {
        $money = Money::fromSettings();
        $format = $this->dateFormat();

        return Invoice::query()->where('account_id', $this->accountId)->with('lines')->latest('issue_date')->latest('id')->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'date' => $invoice->issue_date->format($format),
                'for' => $invoice->period_from !== null && $invoice->period_to !== null
                    ? $invoice->period_from->format($format).' - '.$invoice->period_to->format($format)
                    : (string) $invoice->lines->first()?->description,
                'total' => $money->format((string) $invoice->total),
                'paid' => $money->format((string) $invoice->paid_total),
                'balance' => $money->format((string) $invoice->balance),
                'status' => $invoice->status->label(),
                'color' => $invoice->status->color(),
                'open' => in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartlyPaid], true),
                'cancelled' => $invoice->status === InvoiceStatus::Cancelled,
                'reason' => $invoice->cancel_reason,
            ])->all();
    }

    /**
     * Payments, removed ones too (shown struck through with the reason).
     *
     * @return list<array{id: int, date: string, invoice: string, method: string, reference: string, amount: string, staff: string, removed: bool, reason: ?string}>
     */
    #[Computed]
    public function payments(): array
    {
        $money = Money::fromSettings();
        $format = $this->dateFormat().' H:i';

        return Payment::withTrashed()->where('account_id', $this->accountId)->with(['invoice', 'method', 'user'])->latest('paid_at')->latest('id')->get()
            ->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'date' => $payment->paid_at->format($format),
                'invoice' => (string) $payment->invoice?->number,
                'method' => (string) $payment->method?->name,
                'reference' => (string) $payment->reference,
                'amount' => $money->format((string) $payment->amount),
                'staff' => $payment->user?->name ?? __('System'),
                'removed' => $payment->trashed(),
                'reason' => $payment->remove_reason,
            ])->all();
    }

    /**
     * @return list<array{date: string, text: string, debit: string, credit: string, balance: string}>
     */
    #[Computed]
    public function statement(): array
    {
        $money = Money::fromSettings();
        $format = $this->dateFormat();

        return array_map(fn (array $row): array => [
            'date' => $row['date']->format($format),
            'text' => $row['text'],
            'debit' => $row['debit'] === null ? '' : $money->format($row['debit']),
            'credit' => $row['credit'] === null ? '' : $money->format($row['credit']),
            'balance' => $money->format($row['balance']),
        ], Statement::for($this->account));
    }

    protected function invoiceReissued(Invoice $invoice, string $message): void
    {
        $this->invoiceChanged('billing-new-invoice', $message);
    }

    protected function invoiceChanged(string $dialog, string $message): void
    {
        unset($this->invoices, $this->payments, $this->statement, $this->dues, $this->payingBalance);
        $this->dispatch('modal-close', name: $dialog);
        $this->dispatch('account-changed')->to('pages::customers.plan-card');
        $this->toast($message);
    }

    private function dateFormat(): string
    {
        return (string) AppSetting::valueOf('date_format', 'd/m/Y');
    }
};
