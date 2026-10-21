<?php

namespace App\Livewire\Concerns;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\ReissueInvoice;
use App\Billing\BillingSettings;
use App\Billing\Decimal;
use App\Billing\InvoiceDraft;
use App\Billing\LineInput;
use App\Enums\InvoiceKind;
use App\Exceptions\InvoiceAlreadyCancelled;
use App\Exceptions\InvoiceHasPayments;
use App\Exceptions\InvoicingNotReady;
use App\Models\Account;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The invoice dialog (partials.invoice-form): New invoice (a manual invoice such as a CPE, with a payment taken now)
 * and Cancel and reissue (the old invoice's lines and date filled in, a reason, no payment). Used with
 * ManagesInvoices and TakesPayment.
 */
trait WritesInvoices
{
    /**
     * The lines, round off and issue date (Y-m-d). A line's source is its position on the invoice being reissued.
     *
     * @var list<array{description: string, details: string, quantity: string, unit_price: string, discount: string, taxed: bool, item_code: string, source: ?int}>
     */
    public array $lines = [];

    public bool $round_off = false;

    public string $invoice_date = '';

    /**
     * Cancel and reissue: the invoice being replaced; null for New invoice.
     */
    #[Locked]
    public ?int $reissuing_invoice_id = null;

    /**
     * Cancel and reissue: the old lines' discounts by position, kept for staff who cannot set discounts.
     *
     * @var list<string>
     */
    #[Locked]
    public array $line_discounts = [];

    public string $reissue_reason = '';

    abstract public function account(): Account;

    /**
     * Called after Cancel and reissue with the new invoice.
     */
    abstract protected function invoiceReissued(Invoice $invoice, string $message): void;

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
     * The number of the invoice being reissued, for the dialog heading.
     */
    #[Computed]
    public function reissuingNumber(): ?string
    {
        return $this->reissuing_invoice_id === null ? null : $this->ownInvoice($this->reissuing_invoice_id)->number;
    }

    /**
     * The totals as staff type: taxable, tax lines, round off and total.
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

    public function openNewInvoice(): void
    {
        $this->authorizeLive('create-invoices');
        $this->resetValidation();
        $this->reissuing_invoice_id = null;
        $this->line_discounts = [];
        $this->lines = [$this->blankLine()];
        $this->round_off = false;
        $this->invoice_date = now()->format('Y-m-d');
        $this->resetPayment();
        $this->dispatch('modal-open', name: 'billing-new-invoice');
    }

    /**
     * Cancel and reissue: the dialog filled in with the invoice's lines, round off and date.
     */
    public function openReissueInvoice(int $invoiceId): void
    {
        $this->authorizeReissue();
        $this->resetValidation();
        $invoice = $this->ownInvoice($invoiceId);
        $this->reissuing_invoice_id = $invoice->id;
        $this->line_discounts = [];
        $this->lines = [];

        foreach ($invoice->lines as $index => $line) {
            $this->line_discounts[] = Decimal::trim((string) $line->discount_percent);
            $this->lines[] = [
                'description' => $line->description,
                'details' => (string) $line->sub_description,
                'quantity' => Decimal::trim((string) $line->quantity),
                'unit_price' => (string) $line->unit_price,
                'discount' => Decimal::trim((string) $line->discount_percent),
                'taxed' => (bool) $line->taxed,
                'item_code' => (string) $line->item_code,
                'source' => $index,
            ];
        }

        $this->round_off = Decimal::compare((string) $invoice->round_off, '0') !== 0;
        $this->invoice_date = $invoice->issue_date->format('Y-m-d');
        $this->reissue_reason = '';
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
     * The dialog's submit: New invoice or Cancel and reissue.
     */
    public function saveInvoice(IssueInvoice $issue, ReissueInvoice $reissue): void
    {
        $this->reissuing_invoice_id === null ? $this->createInvoice($issue) : $this->reissueInvoice($reissue);
    }

    /**
     * Issues the manual invoice, with the payment taken now if any.
     */
    public function createInvoice(IssueInvoice $issue): void
    {
        $this->authorizeLive('create-invoices');
        $total = $this->newInvoiceTotal;
        $this->validate([...$this->invoiceRules(), ...$this->paymentRules($total)], $this->invoiceMessages(),
            [...$this->invoiceAttributes(), ...$this->paymentAttributes()]);

        try {
            $invoice = $issue->handle($this->account(), $this->lineInputs(), $this->round_off, InvoiceKind::Manual,
                Carbon::createFromFormat('!Y-m-d', $this->invoice_date) ?: now(), $this->paymentInput($total));
        } catch (InvoicingNotReady) {
            $this->toast(__('Invoicing is off or the company details are missing. Check Billing settings.'), 'danger');

            return;
        }

        $this->invoiceChanged('billing-new-invoice', __('Invoice :number issued.', ['number' => $invoice->number]));
    }

    /**
     * Cancels the invoice being reissued and issues its replacement.
     */
    public function reissueInvoice(ReissueInvoice $reissue): void
    {
        $this->authorizeReissue();
        abort_if($this->reissuing_invoice_id === null, 404);
        $this->reissue_reason = trim($this->reissue_reason);
        $this->validate([...$this->invoiceRules(), 'reissue_reason' => ['required', 'string', 'max:255']], $this->invoiceMessages(),
            [...$this->invoiceAttributes(), 'reissue_reason' => __('reason')]);

        try {
            $invoice = $reissue->handle($this->ownInvoice($this->reissuing_invoice_id)->id, $this->lineInputs(), $this->round_off,
                Carbon::createFromFormat('!Y-m-d', $this->invoice_date) ?: now(), $this->reissue_reason);
        } catch (InvoiceHasPayments) {
            $this->addError('reissue_reason', __('This invoice has payments. Remove them first.'));

            return;
        } catch (InvoiceAlreadyCancelled) {
            $this->addError('reissue_reason', __('This invoice is already cancelled.'));

            return;
        } catch (InvoicingNotReady) {
            $this->toast(__('Invoicing is off or the company details are missing. Check Billing settings.'), 'danger');

            return;
        }

        $this->reissuing_invoice_id = null;
        $this->invoiceReissued($invoice, __('Invoice :number issued, replacing :old.', ['number' => $invoice->number, 'old' => (string) $invoice->replaces?->number]));
    }

    /**
     * Cancel and reissue needs both Cancel invoices and Create invoices.
     */
    protected function authorizeReissue(): void
    {
        $this->authorizeLive('cancel-invoices');
        $this->authorizeLive('create-invoices');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function invoiceRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.details' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_price' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'lines.*.discount' => $this->canDiscount ? ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'] : [],
            'lines.*.taxed' => ['boolean'],
            'lines.*.item_code' => ['nullable', 'string', 'max:20'],
            'round_off' => ['boolean'],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function invoiceMessages(): array
    {
        return ['invoice_date.before_or_equal' => __('An invoice cannot be dated in the future.')];
    }

    /**
     * @return array<string, string>
     */
    private function invoiceAttributes(): array
    {
        return [
            'lines.*.description' => __('description'),
            'lines.*.details' => __('more details'),
            'lines.*.quantity' => __('quantity'),
            'lines.*.unit_price' => __('unit price'),
            'lines.*.discount' => __('discount'),
            'lines.*.item_code' => (string) $this->itemCodeLabel,
            'invoice_date' => __('invoice date'),
        ];
    }

    /**
     * @return array{description: string, details: string, quantity: string, unit_price: string, discount: string, taxed: bool, item_code: string, source: ?int}
     */
    private function blankLine(): array
    {
        $settings = BillingSettings::current();

        return [
            'description' => '', 'details' => '', 'quantity' => '1', 'unit_price' => '', 'discount' => '0', 'taxed' => true,
            'item_code' => $settings->tax()->itemCodeLabel() === null ? '' : ($settings->taxConfig['item_code'] ?? ''),
            'source' => null,
        ];
    }

    /**
     * The lines as the calculator takes them. A discount needs Set customer pricing; without it a new line has none
     * and a reissued line keeps the old invoice's.
     *
     * @return list<LineInput>
     */
    private function lineInputs(): array
    {
        $codes = $this->itemCodeLabel !== null;

        return array_map(fn (array $line): LineInput => new LineInput(
            description: trim((string) $line['description']),
            subDescription: trim((string) ($line['details'] ?? '')) === '' ? null : trim((string) $line['details']),
            itemCode: $codes && trim((string) $line['item_code']) !== '' ? trim((string) $line['item_code']) : null,
            quantity: (string) $line['quantity'],
            unitPrice: (string) $line['unit_price'],
            discountPercent: $this->canDiscount
                ? (is_numeric($line['discount']) ? (string) $line['discount'] : '0')
                : (is_int($line['source'] ?? null) ? ($this->line_discounts[$line['source']] ?? '0') : '0'),
            taxed: (bool) $line['taxed'],
        ), $this->lines);
    }

    /**
     * The worked-out invoice, or null while a line has no valid quantity or price yet.
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

        return IssueInvoice::draft($this->account(), $this->lineInputs(), $this->round_off, BillingSettings::current());
    }
}
