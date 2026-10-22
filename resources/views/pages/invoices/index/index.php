<?php

use App\Billing\Decimal;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\InvoiceTaxLine;
use App\Models\PaymentMethod;
use App\Support\CsvExport;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every invoice, newest first, with filters (period, status, kind, payment method, customer), a totals row and a
 * CSV export with the GST columns for filing. Totals and the export need View sales.
 */
new #[Title('Invoices')] class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    private const FILTERS = ['from', 'to', 'status', 'kind', 'method', 'search'];

    /**
     * The period, Y-m-d.
     */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $kind = '';

    #[Url]
    public string $method = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('view-invoices'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        return $this->filtered()->latest('issue_date')->latest('id')->paginate(50);
    }

    /**
     * @return list<array{id: int, number: string, date: string, customer: string, username: string, accountId: int, total: string, paid: string, balance: string, status: string, color: string, kind: string}>
     */
    #[Computed]
    public function rows(): array
    {
        $money = Money::fromSettings();
        $format = (string) AppSetting::valueOf('date_format', 'd/m/Y');

        return collect($this->invoices->items())->map(fn (Invoice $invoice): array => [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'date' => $invoice->issue_date->format($format),
            'customer' => (string) ($invoice->customer['name'] ?? ''),
            'username' => (string) ($invoice->customer['username'] ?? ''),
            'accountId' => $invoice->account_id,
            'total' => $money->format((string) $invoice->total),
            'paid' => $money->format((string) $invoice->paid_total),
            'balance' => $money->format((string) $invoice->balance),
            'status' => $invoice->status->label(),
            'color' => $invoice->status->color(),
            'kind' => $invoice->kind->label(),
        ])->values()->all();
    }

    /**
     * Totals of the filtered invoices, cancelled ones left out; null without View sales.
     *
     * @return ?array{total: string, paid: string, balance: string}
     */
    #[Computed]
    public function totals(): ?array
    {
        if (! Gate::allows('view-sales')) {
            return null;
        }

        $sums = $this->filtered()->where('status', '!=', InvoiceStatus::Cancelled)
            ->selectRaw('COALESCE(SUM(total), 0) AS total, COALESCE(SUM(paid_total), 0) AS paid, COALESCE(SUM(balance), 0) AS balance')
            ->toBase()->first();
        $money = Money::fromSettings();

        return [
            'total' => $money->format((string) ($sums->total ?? '0')),
            'paid' => $money->format((string) ($sums->paid ?? '0')),
            'balance' => $money->format((string) ($sums->balance ?? '0')),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(InvoiceStatus::cases())->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function kindOptions(): array
    {
        return collect(InvoiceKind::cases())->mapWithKeys(fn (InvoiceKind $kind): array => [$kind->value => $kind->label()])->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function methodOptions(): array
    {
        return PaymentMethod::query()->orderBy('position')->orderBy('id')->pluck('name', 'id')->all();
    }

    /**
     * The filtered invoices as a CSV, one row per invoice with its tax split for GST filing.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('view-sales');

        $rows = function (): Generator {
            foreach ($this->filtered()->with('taxLines')->oldest('issue_date')->oldest('id')->lazy(500) as $invoice) {
                $tax = ['CGST' => '0', 'SGST' => '0', 'IGST' => '0', 'other' => '0'];

                foreach ($invoice->taxLines as $line) {
                    /** @var InvoiceTaxLine $line */
                    $key = array_key_exists($line->name, $tax) ? $line->name : 'other';
                    $tax[$key] = Decimal::add($tax[$key], (string) $line->amount);
                }

                yield [
                    $invoice->number,
                    $invoice->issue_date->format('Y-m-d'),
                    (string) ($invoice->customer['name'] ?? ''),
                    (string) ($invoice->customer['username'] ?? ''),
                    (string) ($invoice->customer['tax_number'] ?? ''),
                    (string) ($invoice->customer['place_label'] ?? ''),
                    (string) $invoice->taxable_total,
                    Decimal::round($tax['CGST'], Currency::decimals()),
                    Decimal::round($tax['SGST'], Currency::decimals()),
                    Decimal::round($tax['IGST'], Currency::decimals()),
                    Decimal::round($tax['other'], Currency::decimals()),
                    (string) $invoice->round_off,
                    (string) $invoice->total,
                    (string) $invoice->paid_total,
                    (string) $invoice->balance,
                    $invoice->status->label(),
                ];
            }
        };

        return CsvExport::download('invoices-'.now()->format('Y-m-d').'.csv', [
            'Number', 'Date', 'Customer', 'Username', 'GSTIN', 'Place of supply', 'Taxable', 'CGST', 'SGST', 'IGST', 'Other tax',
            'Round off', 'Total', 'Paid', 'Balance', 'Status',
        ], $rows());
    }

    /**
     * @return Builder<Invoice>
     */
    private function filtered(): Builder
    {
        $search = trim($this->search);

        return Invoice::query()
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1, fn (Builder $query) => $query->where('issue_date', '>=', $this->from))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) === 1, fn (Builder $query) => $query->where('issue_date', '<=', $this->to))
            ->when(InvoiceStatus::tryFrom($this->status), fn (Builder $query, InvoiceStatus $status) => $query->where('status', $status))
            ->when(InvoiceKind::tryFrom($this->kind), fn (Builder $query, InvoiceKind $kind) => $query->where('kind', $kind))
            ->when(ctype_digit($this->method), fn (Builder $query) => $query->whereHas('payments', fn (Builder $payments) => $payments->where('payment_method_id', (int) $this->method)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('number', 'like', "%{$search}%")
                ->orWhereHas('account', fn (Builder $account) => $account->withTrashed()
                    ->where(fn (Builder $name) => $name->where('username', 'like', "%{$search}%")->orWhere('fullname', 'like', "%{$search}%")))));
    }
};
