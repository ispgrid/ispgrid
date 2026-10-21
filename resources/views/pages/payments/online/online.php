<?php

use App\Actions\Payments\SettleOnlinePayment;
use App\Enums\OnlinePaymentStatus;
use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\OnlinePayment;
use App\Payments\PaymentGateways;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every quick pay attempt, newest first: what the customer was asked to pay, the gateway's answer and where it
 * stands. Staff who record payments can ask the gateway again about a pending one, and close a Needs attention one
 * after refunding or settling it by hand.
 */
new #[Title('Online payments')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithPagination;

    #[Url]
    public string $status = '';

    #[Locked]
    public ?int $handling = null;

    public string $handled_note = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('view-invoices'), 403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function payments(): LengthAwarePaginator
    {
        return OnlinePayment::query()
            ->with(['account', 'handler'])
            ->when(OnlinePaymentStatus::tryFrom($this->status), fn ($query, OnlinePaymentStatus $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(50);
    }

    /**
     * @return list<array{id: int, date: string, accountId: int, username: string, amount: string, parts: string, gateway: string, status: string, color: string, txn: string, note: string, handled: string, canCheck: bool, canHandle: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $money = Money::fromSettings();
        $format = AppSetting::valueOf('date_format', 'd/m/Y').' H:i';
        $gateways = app(PaymentGateways::class);
        $canAct = Gate::allows('record-payments');

        return collect($this->payments->items())->map(function (OnlinePayment $payment) use ($money, $format, $gateways, $canAct): array {
            $class = $gateways->class($payment->gateway);
            $renewal = (float) $payment->renewal_amount > 0;

            return [
                'id' => $payment->id,
                'date' => $payment->created_at?->format($format) ?? '',
                'accountId' => $payment->account_id,
                'username' => (string) $payment->account?->username,
                'amount' => $money->format((string) $payment->amount),
                'parts' => $renewal ? __('Dues :dues, renewal :renewal', ['dues' => $money->format((string) $payment->dues_amount), 'renewal' => $money->format((string) $payment->renewal_amount)]) : '',
                'gateway' => $class === null ? $payment->gateway : $class::label(),
                'status' => $payment->status->label(),
                'color' => match ($payment->status) {
                    OnlinePaymentStatus::Paid => 'green',
                    OnlinePaymentStatus::Pending => 'blue',
                    OnlinePaymentStatus::NeedsAttention => $payment->handled_at === null ? 'amber' : 'zinc',
                    OnlinePaymentStatus::Failed => 'red',
                },
                'txn' => (string) $payment->gateway_txn_id,
                'note' => (string) $payment->note,
                'handled' => $payment->handled_at === null ? '' : __('Handled by :name: :note', ['name' => $payment->handler->name ?? __('System'), 'note' => (string) $payment->handled_note]),
                'canCheck' => $canAct && $payment->status === OnlinePaymentStatus::Pending,
                'canHandle' => $canAct && $payment->status === OnlinePaymentStatus::NeedsAttention && $payment->handled_at === null,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(OnlinePaymentStatus::cases())->mapWithKeys(fn (OnlinePaymentStatus $status): array => [$status->value => $status->label()])->all();
    }

    /**
     * Asks the gateway now about a payment still pending.
     */
    public function checkNow(int $id, SettleOnlinePayment $settle): void
    {
        abort_unless(Gate::allows('record-payments'), 403);
        $payment = OnlinePayment::query()->where('status', OnlinePaymentStatus::Pending)->findOrFail($id);

        try {
            $settled = $settle->handle($payment->public_id);
        } catch (Throwable $exception) {
            report($exception);
            $this->toast(__('The gateway could not be asked. Try again later.'), 'danger');

            return;
        }

        unset($this->payments, $this->rows);
        $this->toast(__('Status: :status', ['status' => $settled->status->label()]));
    }

    public function openHandle(int $id): void
    {
        abort_unless(Gate::allows('record-payments'), 403);
        OnlinePayment::query()->where('status', OnlinePaymentStatus::NeedsAttention)->whereNull('handled_at')->findOrFail($id);
        $this->handling = $id;
        $this->handled_note = '';
        $this->resetValidation();
        $this->dispatch('modal-open', name: 'handle-online-payment');
    }

    /**
     * Closes a Needs attention payment once staff refunded it or settled it by hand. Logged with their note.
     */
    public function markHandled(): void
    {
        abort_unless(Gate::allows('record-payments'), 403);
        $this->handled_note = trim($this->handled_note);
        $this->validate(['handled_note' => ['required', 'string', 'max:255']], attributes: ['handled_note' => __('what was done')]);
        $payment = OnlinePayment::query()->where('status', OnlinePaymentStatus::NeedsAttention)->whereNull('handled_at')->findOrFail((int) $this->handling);

        $payment->update(['handled_at' => now(), 'handled_by' => auth()->id(), 'handled_note' => $this->handled_note]);

        activity()
            ->performedOn(Account::withTrashed()->findOrFail($payment->account_id))
            ->event('online-payment-handled')
            ->withProperties(['online_payment' => $payment->public_id])
            ->withChanges(['old' => [], 'attributes' => ['amount' => (string) $payment->amount, 'reference' => $payment->gateway_txn_id, 'note' => $this->handled_note]])
            ->log('online-payment-handled');

        $this->handling = null;
        unset($this->payments, $this->rows);
        $this->dispatch('modal-close', name: 'handle-online-payment');
        $this->toast(__('Marked as handled.'));
    }
};
