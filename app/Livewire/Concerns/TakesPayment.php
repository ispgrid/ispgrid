<?php

namespace App\Livewire\Concerns;

use App\Billing\Decimal;
use App\Billing\PaymentInput;
use App\Models\PaymentMethod;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The payment section of the recharge dialogs and New invoice: "Payment received now" with the method, the amount
 * (empty means the whole total), a reference, when, and a note for staff. Off, the invoice stays unpaid, due by the payment terms.
 */
trait TakesPayment
{
    public bool $paid_now = true;

    public string $payment_method_id = '';

    public string $payment_amount = '';

    public string $payment_reference = '';

    public string $payment_paid_at = '';

    public string $payment_note = '';

    /**
     * The payment methods turned on, by id, in their order.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function paymentMethods(): array
    {
        return PaymentMethod::query()->forStaff()->where('active', true)->orderBy('position')->orderBy('id')->pluck('name', 'id')->all();
    }

    protected function resetPayment(): void
    {
        $this->paid_now = true;
        $this->payment_method_id = (string) (array_key_first($this->paymentMethods) ?? '');
        $this->payment_amount = '';
        $this->payment_reference = '';
        $this->payment_paid_at = now()->format('Y-m-d\TH:i');
        $this->payment_note = '';
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function paymentRules(string $total): array
    {
        if (! $this->takesPayment($total)) {
            return [];
        }

        return [
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('active', true)],
            'payment_amount' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/', function (string $attribute, mixed $value, Closure $fail) use ($total): void {
                if (is_string($value) && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) === 1
                    && (Decimal::compare($value, '0') <= 0 || Decimal::compare($value, $total) > 0)) {
                    $fail(__('The payment must be more than 0 and at most the total.'));
                }
            }],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'payment_paid_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:now'],
            'payment_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function paymentAttributes(): array
    {
        return [
            'payment_method_id' => __('method'),
            'payment_amount' => __('amount received'),
            'payment_reference' => __('reference'),
            'payment_paid_at' => __('received at'),
            'payment_note' => __('note'),
        ];
    }

    /**
     * The payment to record with the invoice, or null when none is taken now (or the total is 0).
     */
    protected function paymentInput(string $total): ?PaymentInput
    {
        if (! $this->takesPayment($total)) {
            return null;
        }

        $reference = trim($this->payment_reference);
        $note = trim($this->payment_note);

        return new PaymentInput(
            (int) $this->payment_method_id,
            trim($this->payment_amount) === '' ? $total : trim($this->payment_amount),
            $reference === '' ? null : $reference,
            Carbon::createFromFormat('!Y-m-d\TH:i', $this->payment_paid_at) ?: now(),
            $note === '' ? null : $note,
        );
    }

    private function takesPayment(string $total): bool
    {
        return $this->paid_now && Decimal::compare($total, '0') > 0;
    }
}
