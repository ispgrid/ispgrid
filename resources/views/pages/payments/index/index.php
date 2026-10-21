<?php

use App\Billing\Decimal;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\AppSetting;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\CsvExport;
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
 * Every payment, newest first, removed ones struck through, with filters (period, method, staff), totals per
 * method and a CSV export. Totals and the export need View sales.
 */
new #[Title('Payments')] class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    private const FILTERS = ['from', 'to', 'method', 'staff'];

    /**
     * The period, Y-m-d.
     */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $method = '';

    #[Url]
    public string $staff = '';

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
    public function payments(): LengthAwarePaginator
    {
        return $this->filtered()->with(['invoice', 'method', 'user'])->latest('paid_at')->latest('id')->paginate(50);
    }

    /**
     * @return list<array{id: int, date: string, invoice: string, invoiceId: int, customer: string, method: string, reference: string, amount: string, staff: string, removed: bool, reason: ?string}>
     */
    #[Computed]
    public function rows(): array
    {
        $money = Money::fromSettings();
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';

        return collect($this->payments->items())->map(fn (Payment $payment): array => [
            'id' => $payment->id,
            'date' => $payment->paid_at->format($format),
            'invoice' => (string) $payment->invoice?->number,
            'invoiceId' => $payment->invoice_id,
            'customer' => (string) ($payment->invoice?->customer['name'] ?? ''),
            'method' => (string) $payment->method?->name,
            'reference' => (string) $payment->reference,
            'amount' => $money->format((string) $payment->amount),
            'staff' => $payment->user?->name ?? __('System'),
            'removed' => $payment->trashed(),
            'reason' => $payment->remove_reason,
        ])->values()->all();
    }

    /**
     * Received per method over the filtered payments, removed ones left out; empty without View sales.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function methodTotals(): array
    {
        if (! Gate::allows('view-sales')) {
            return [];
        }

        $money = Money::fromSettings();
        $names = PaymentMethod::query()->pluck('name', 'id');

        return $this->filtered()->withoutTrashed()->reorder()->groupBy('payment_method_id')
            ->selectRaw('payment_method_id, SUM(amount) AS received')->toBase()->get()
            ->mapWithKeys(fn (object $row): array => [(string) ($names[$row->payment_method_id] ?? '') => $money->format(Decimal::round((string) $row->received, 2))])
            ->sortKeys()->all();
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
     * @return array<int, string>
     */
    #[Computed]
    public function staffOptions(): array
    {
        return User::query()->whereIn('id', Payment::withTrashed()->whereNotNull('user_id')->distinct()->select('user_id'))->orderBy('name')->pluck('name', 'id')->all();
    }

    public function export(): StreamedResponse
    {
        $this->authorize('view-sales');

        $rows = function (): Generator {
            foreach ($this->filtered()->with(['invoice', 'method', 'user'])->oldest('paid_at')->oldest('id')->lazy(500) as $payment) {
                yield [
                    $payment->paid_at->format('Y-m-d H:i'),
                    (string) $payment->invoice?->number,
                    (string) ($payment->invoice?->customer['name'] ?? ''),
                    (string) $payment->method?->name,
                    (string) $payment->reference,
                    (string) $payment->amount,
                    $payment->user?->name ?? 'System',
                    $payment->trashed() ? 'Removed: '.$payment->remove_reason : '',
                ];
            }
        };

        return CsvExport::download('payments-'.now()->format('Y-m-d').'.csv',
            ['Date', 'Invoice', 'Customer', 'Method', 'Reference', 'Amount', 'Recorded by', 'Removed'], $rows());
    }

    /**
     * @return Builder<Payment>
     */
    private function filtered(): Builder
    {
        return Payment::withTrashed()
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1, fn (Builder $query) => $query->where('paid_at', '>=', $this->from.' 00:00:00'))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) === 1, fn (Builder $query) => $query->where('paid_at', '<=', $this->to.' 23:59:59'))
            ->when(ctype_digit($this->method), fn (Builder $query) => $query->where('payment_method_id', (int) $this->method))
            ->when(ctype_digit($this->staff), fn (Builder $query) => $query->where('user_id', (int) $this->staff));
    }
};
