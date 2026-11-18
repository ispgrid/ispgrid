<?php

use App\Billing\Decimal;
use App\Enums\OnlinePaymentStatus;
use App\Http\Middleware\SelfcareSession;
use App\Models\AppSetting;
use App\Models\OnlinePayment;
use App\Support\Money;
use App\Support\PublicPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Where a payment started in Selfcare lands after the gateway. Sits outside the selfcare middleware: a visitor
 * without this customer's valid Selfcare session (or with another customer's) goes to the public result page, which
 * shows no personal details, never to a login. Reads the row only; the gateway is asked elsewhere (the return and
 * the scheduled check). Unknown ids are not found, as on the public result page.
 */
new class extends Component
{
    #[Locked]
    public string $publicId = '';

    public function mount(string $publicId): void
    {
        $payment = OnlinePayment::query()->where('public_id', $publicId)->firstOrFail();

        if (! $this->owns($payment)) {
            $this->redirectRoute('pay.result', $publicId);

            return;
        }

        $this->publicId = $publicId;
    }

    /**
     * Runs on every later request (polls, refreshes): the session may have ended since mount.
     */
    public function hydrate(): void
    {
        if ($this->publicId !== '' && ! $this->owns($this->payment)) {
            $this->redirectRoute('pay.result', $this->publicId);
        }
    }

    /**
     * Whether a valid Selfcare session may see this attempt: the payment's account is one of the connections the
     * session may open (every eligible one on its mobile for a mobile login, else the open one).
     */
    private function owns(OnlinePayment $payment): bool
    {
        return SelfcareSession::validAccount() !== null && SelfcareSession::connections()->contains('id', $payment->account_id);
    }

    /**
     * Re-read on every request, so polling sees the settled state.
     */
    #[Computed]
    public function payment(): OnlinePayment
    {
        return OnlinePayment::query()->where('public_id', $this->publicId)->firstOrFail();
    }

    /**
     * @return array{state: string, amount: string, receipts: list<array{number: string, url: string}>, expiry: ?string}
     */
    #[Computed]
    public function summary(): array
    {
        $payment = $this->payment;
        $expiry = $payment->account?->expiry;
        $renewed = $payment->status === OnlinePaymentStatus::Paid && Decimal::compare((string) $payment->renewal_amount, '0') > 0;

        return [
            'state' => $payment->publicState(),
            'amount' => Money::fromSettings()->format((string) $payment->amount),
            'receipts' => array_map(fn (int $id): array => ['number' => 'RCPT-'.$id, 'url' => route('selfcare.receipt', $id)], $payment->receiptIds()),
            'expiry' => $renewed ? $expiry?->format((string) AppSetting::valueOf('date_format', 'd/m/Y').' H:i') : null,
        ];
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::public', [...PublicPage::data(), 'title' => __('Payment')]);
    }
};
