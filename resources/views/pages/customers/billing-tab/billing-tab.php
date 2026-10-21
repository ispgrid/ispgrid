<?php

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RemovePayment;
use App\Billing\BillingSettings;
use App\Billing\Decimal;
use App\Billing\InvoiceDraft;
use App\Billing\LineInput;
use App\Billing\Statement;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPayments;
use App\Exceptions\InvoicingNotReady;
use App\Exceptions\PaymentExceedsBalance;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Concerns\TakesPayment;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The customer's Billing tab: dues, invoices, payments and the statement, with New invoice (manual invoices such
 * as a CPE), Record payment, Remove payment and Cancel invoice. Read-only for a deleted customer.
 */
new class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, TakesPayment;

    #[Locked]
    public int $accountId;

    /**
     * New invoice: its lines, round off and issue date (Y-m-d).
     *
     * @var list<array{description: string, quantity: string, unit_price: string, discount: string, taxed: bool, item_code: string}>
     */
    public array $lines = [];

    public bool $round_off = false;

    public string $invoice_date = '';

    /**
     * The invoice a payment is being recorded for, the payment being removed and the invoice being cancelled.
     */
    #[Locked]
    public ?int $paying_invoice_id = null;

    #[Locked]
    public ?int $removing_payment_id = null;

    #[Locked]
    public ?int $cancelling_invoice_id = null;

    public string $remove_reason = '';

    public string $cancel_reason = '';

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
     * What staff may do here: nothing on a deleted customer.
     *
     * @return array{create: bool, pay: bool, cancel: bool}
     */
    #[Computed]
    public function can(): array
    {
        $live = ! $this->account->trashed();

        return [
            'create' => $live && Gate::allows('create-invoices') && BillingSettings::current()->canIssue(),
            'pay' => $live && Gate::allows('record-payments'),
            'cancel' => $live && Gate::allows('cancel-invoices'),
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
                'color' => match ($invoice->status) {
                    InvoiceStatus::Paid => 'green',
                    InvoiceStatus::PartlyPaid => 'amber',
                    InvoiceStatus::Unpaid => 'red',
                    InvoiceStatus::Cancelled => 'zinc',
                },
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

    /**
     * The item code label of the tax setup (SAC/HSN in India), or null when lines have no code.
     */
    #[Computed]
    public function itemCodeLabel(): ?string
    {
        return BillingSettings::current()->tax()->itemCodeLabel();
    }

    #[Computed]
    public function canDiscount(): bool
    {
        return Gate::allows('set-customer-pricing');
    }

    /**
     * The New invoice totals as staff type: taxable, tax lines, round off and total.
     *
     * @return list<array{label: string, value: string, total: bool}>
     */
    #[Computed]
    public function newInvoiceTotals(): array
    {
        $draft = $this->draft();

        if ($draft === null) {
            return [];
        }

        $money = Money::fromSettings();
        $rows = [['label' => __('Taxable'), 'value' => $money->format($draft->taxableTotal), 'total' => false]];

        foreach ($draft->taxLines as $taxLine) {
            $rows[] = ['label' => $taxLine->label(), 'value' => $money->format($taxLine->amount), 'total' => false];
        }

        if (Decimal::compare($draft->roundOff, '0') !== 0) {
            $rows[] = ['label' => __('Round off'), 'value' => $money->format($draft->roundOff), 'total' => false];
        }

        $rows[] = ['label' => __('Total'), 'value' => $money->format($draft->total), 'total' => true];

        return $rows;
    }

    #[Computed]
    public function newInvoiceTotal(): string
    {
        return $this->draft()->total ?? '0';
    }

    /**
     * The balance of the invoice a payment is being recorded for.
     */
    #[Computed]
    public function payingBalance(): string
    {
        return $this->paying_invoice_id === null ? '0' : (string) $this->ownInvoice($this->paying_invoice_id)->balance;
    }

    public function openNewInvoice(): void
    {
        $this->authorizeLive('create-invoices');
        $this->resetValidation();
        $this->lines = [$this->blankLine()];
        $this->round_off = false;
        $this->invoice_date = now()->format('Y-m-d');
        $this->resetPayment();
        $this->dispatch('modal-open', name: 'billing-new-invoice');
    }

    public function addLine(): void
    {
        $this->authorizeLive('create-invoices');

        if (count($this->lines) < 20) {
            $this->lines[] = $this->blankLine();
        }
    }

    public function removeLine(int $index): void
    {
        $this->authorizeLive('create-invoices');

        if (count($this->lines) > 1) {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }
    }

    /**
     * Issues the manual invoice, with the payment taken now if any.
     */
    public function createInvoice(IssueInvoice $issue): void
    {
        $this->authorizeLive('create-invoices');
        $total = $this->newInvoiceTotal;

        $this->validate([
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_price' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'lines.*.discount' => $this->canDiscount ? ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'] : [],
            'lines.*.taxed' => ['boolean'],
            'lines.*.item_code' => ['nullable', 'string', 'max:20'],
            'round_off' => ['boolean'],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            ...$this->paymentRules($total),
        ], ['invoice_date.before_or_equal' => __('An invoice cannot be dated in the future.')], [
            'lines.*.description' => __('description'),
            'lines.*.quantity' => __('quantity'),
            'lines.*.unit_price' => __('unit price'),
            'lines.*.discount' => __('discount'),
            'lines.*.item_code' => (string) $this->itemCodeLabel,
            'invoice_date' => __('invoice date'),
            ...$this->paymentAttributes(),
        ]);

        try {
            $invoice = $issue->handle($this->account, $this->lineInputs(), $this->round_off, InvoiceKind::Manual,
                Carbon::createFromFormat('!Y-m-d', $this->invoice_date) ?: now(), $this->paymentInput($total));
        } catch (InvoicingNotReady) {
            $this->toast(__('Invoicing is off or the company details are missing. Check Billing settings.'), 'danger');

            return;
        }

        $this->finish('billing-new-invoice', __('Invoice :number issued.', ['number' => $invoice->number]));
    }

    public function openRecordPayment(int $invoiceId): void
    {
        $this->authorizeLive('record-payments');
        $invoice = $this->ownInvoice($invoiceId);
        $this->resetValidation();
        $this->paying_invoice_id = $invoice->id;
        unset($this->payingBalance);
        $this->resetPayment();
        $this->dispatch('modal-open', name: 'billing-record-payment');
    }

    public function recordPayment(RecordPayment $record): void
    {
        $this->authorizeLive('record-payments');
        abort_if($this->paying_invoice_id === null, 404);
        $balance = $this->payingBalance;
        $this->paid_now = true;

        $this->validate($this->paymentRules($balance), [], $this->paymentAttributes());
        $payment = $this->paymentInput($balance);

        if ($payment === null) {
            $this->finish('billing-record-payment', __('Nothing is due on this invoice.'));

            return;
        }

        try {
            $record->handle($this->paying_invoice_id, $payment);
        } catch (PaymentExceedsBalance) {
            unset($this->payingBalance);
            $this->addError('payment_amount', __('The payment must be more than 0 and at most the total.'));

            return;
        }

        $this->finish('billing-record-payment', __('Payment recorded.'));
    }

    public function openRemovePayment(int $paymentId): void
    {
        $this->authorizeLive('record-payments');
        $this->resetValidation();
        $this->removing_payment_id = Payment::query()->where('account_id', $this->accountId)->findOrFail($paymentId)->id;
        $this->remove_reason = '';
        $this->dispatch('modal-open', name: 'billing-remove-payment');
    }

    public function removePayment(RemovePayment $remove): void
    {
        $this->authorizeLive('record-payments');
        abort_if($this->removing_payment_id === null, 404);
        $this->remove_reason = trim($this->remove_reason);
        $this->validate(['remove_reason' => ['required', 'string', 'max:255']], [], ['remove_reason' => __('reason')]);

        $remove->handle(Payment::query()->where('account_id', $this->accountId)->findOrFail($this->removing_payment_id)->id, $this->remove_reason);
        $this->finish('billing-remove-payment', __('Payment removed.'));
    }

    public function openCancelInvoice(int $invoiceId): void
    {
        $this->authorizeLive('cancel-invoices');
        $this->resetValidation();
        $this->cancelling_invoice_id = $this->ownInvoice($invoiceId)->id;
        $this->cancel_reason = '';
        $this->dispatch('modal-open', name: 'billing-cancel-invoice');
    }

    public function cancelInvoice(CancelInvoice $cancel): void
    {
        $this->authorizeLive('cancel-invoices');
        abort_if($this->cancelling_invoice_id === null, 404);
        $this->cancel_reason = trim($this->cancel_reason);
        $this->validate(['cancel_reason' => ['required', 'string', 'max:255']], [], ['cancel_reason' => __('reason')]);

        try {
            $cancel->handle($this->ownInvoice($this->cancelling_invoice_id)->id, $this->cancel_reason);
        } catch (InvoiceHasPayments) {
            $this->addError('cancel_reason', __('This invoice has payments. Remove them first.'));

            return;
        }

        $this->finish('billing-cancel-invoice', __('Invoice cancelled.'));
    }

    /**
     * The action's permission, and a customer that is not deleted.
     */
    private function authorizeLive(string $ability): void
    {
        $this->authorize($ability);
        abort_if($this->account->trashed(), 403);
    }

    /**
     * One of this customer's invoices (404 for anyone else's).
     */
    private function ownInvoice(int $invoiceId): Invoice
    {
        return Invoice::query()->where('account_id', $this->accountId)->findOrFail($invoiceId);
    }

    /**
     * @return array{description: string, quantity: string, unit_price: string, discount: string, taxed: bool, item_code: string}
     */
    private function blankLine(): array
    {
        $settings = BillingSettings::current();

        return [
            'description' => '', 'quantity' => '1', 'unit_price' => '', 'discount' => '0', 'taxed' => true,
            'item_code' => $settings->tax()->itemCodeLabel() === null ? '' : ($settings->taxConfig['item_code'] ?? ''),
        ];
    }

    /**
     * The lines as the calculator takes them. A discount needs Set customer pricing; without it the line has none.
     *
     * @return list<LineInput>
     */
    private function lineInputs(): array
    {
        $codes = $this->itemCodeLabel !== null;

        return array_map(fn (array $line): LineInput => new LineInput(
            description: trim((string) $line['description']),
            subDescription: null,
            itemCode: $codes && trim((string) $line['item_code']) !== '' ? trim((string) $line['item_code']) : null,
            quantity: (string) $line['quantity'],
            unitPrice: (string) $line['unit_price'],
            discountPercent: $this->canDiscount && is_numeric($line['discount']) ? (string) $line['discount'] : '0',
            taxed: (bool) $line['taxed'],
        ), $this->lines);
    }

    /**
     * The worked-out New invoice, or null while a line has no valid quantity or price yet.
     */
    private function draft(): ?InvoiceDraft
    {
        if ($this->lines === []) {
            return null;
        }

        foreach ($this->lines as $line) {
            if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', (string) $line['quantity']) !== 1 || preg_match('/^\d{1,10}(\.\d{1,2})?$/', (string) $line['unit_price']) !== 1
                || ($this->canDiscount && $line['discount'] !== '' && preg_match('/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/', (string) $line['discount']) !== 1)) {
                return null;
            }
        }

        return IssueInvoice::draft($this->account, $this->lineInputs(), $this->round_off, BillingSettings::current());
    }

    private function finish(string $dialog, string $message): void
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
