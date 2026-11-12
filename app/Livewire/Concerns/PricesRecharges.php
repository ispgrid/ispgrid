<?php

namespace App\Livewire\Concerns;

use App\Billing\BillingSettings;
use App\Billing\ChargeOptions;
use App\Billing\Decimal;
use App\Billing\Discount;
use App\Billing\InvoiceDraft;
use App\Billing\InvoicePolicy;
use App\Billing\PaymentInput;
use App\Billing\RechargeInvoice;
use App\Models\Account;
use App\Models\Plan;
use App\Support\Currency;
use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The money side of the recharge dialogs: a Free customer pays nothing; an invoiced recharge shows the invoice
 * breakdown; otherwise the amount stays an editable field, as before invoicing. Anyone who may recharge can give a
 * discount (amount or percent, with an optional reason), filled in with the customer's saved one; keeping it for
 * future renewals needs Set customer pricing.
 */
trait PricesRecharges
{
    public string $discount_type = Discount::PERCENT;

    public string $discount_value = '';

    public string $discount_reason = '';

    public bool $discount_keep = false;

    /**
     * Fills the discount fields with the customer's saved discount; a saved amount above this plan's price (a cheaper
     * plan) is filled in at the price, as auto renewal caps it.
     */
    protected function resetDiscount(Account $account, ?Plan $plan): void
    {
        $saved = Discount::forAccount($account);
        $amount = $plan !== null && $saved->isAmount() && Decimal::compare($saved->value, (string) $plan->price) > 0 ? (string) $plan->price : $saved->value;
        $this->discount_type = $saved->type;
        $this->discount_value = $saved->isNone() ? '' : ($saved->isAmount() ? $amount : Decimal::trim($saved->value));
        $this->discount_reason = '';
        $this->discount_keep = false;
    }

    /**
     * What the dialog shows for this plan: mode 'free', 'invoice' (with the breakdown rows and the total) or
     * 'amount' (the editable amount).
     *
     * @return array{mode: string, rows: list<array{label: string, value: string, total: bool}>, total: string, can_keep: bool}
     */
    protected function chargeSummary(Account $account, ?Plan $plan): array
    {
        $settings = BillingSettings::current();
        $mode = $account->billing_free ? 'free' : (InvoicePolicy::invoicesRecharge($account, $settings) ? 'invoice' : 'amount');
        $draft = $mode === 'invoice' && $plan !== null ? $this->chargeDraft($account, $plan, $settings) : null;

        return [
            'mode' => $mode,
            'rows' => $draft === null ? [] : $this->breakdownRows($draft, $this->typedDiscount()),
            'total' => $draft === null ? '0' : $draft->total,
            'can_keep' => Gate::allows('set-customer-pricing'),
        ];
    }

    protected function chargeDraft(Account $account, Plan $plan, BillingSettings $settings): InvoiceDraft
    {
        return RechargeInvoice::draft($account, $plan, $settings, $this->typedDiscount(), null, null, null);
    }

    /**
     * The discount fields' rules for a recharge of this plan (none for a Free customer). An amount is at most the
     * plan price; keeping the discount needs Set customer pricing.
     *
     * @param  array{mode: string}  $charge
     * @return array<string, list<mixed>>
     */
    protected function discountRules(array $charge, ?Plan $plan): array
    {
        if ($charge['mode'] === 'free') {
            return [];
        }

        abort_if($this->discount_keep && ! Gate::allows('set-customer-pricing'), 403);
        $price = $plan === null ? Currency::MAX_AMOUNT : (string) $plan->price;

        return [
            'discount_type' => ['required', Rule::in([Discount::PERCENT, Discount::AMOUNT])],
            'discount_value' => $this->discount_type === Discount::AMOUNT
                ? ['nullable', ...Currency::amountRules(), function (string $attribute, mixed $value, Closure $fail) use ($price): void {
                    if (is_numeric($value) && Decimal::compare((string) $value, $price) > 0) {
                        $fail(__('The discount cannot be more than the plan price.'));
                    }
                }]
                : ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'discount_keep' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function discountAttributes(): array
    {
        return ['discount_value' => __('discount'), 'discount_reason' => __('reason')];
    }

    /**
     * The discount given in the dialog, its reason, whether to keep it, and the payment taken now.
     */
    protected function chargeOptions(?PaymentInput $payment): ChargeOptions
    {
        $reason = trim($this->discount_reason);

        return new ChargeOptions(
            discount: $this->typedDiscount(),
            discountReason: $reason === '' ? null : $reason,
            keepDiscount: $this->discount_keep,
            payment: $payment,
        );
    }

    /**
     * The discount as typed: none when the field is empty or not a number yet.
     */
    protected function typedDiscount(): Discount
    {
        $value = trim($this->discount_value);
        $type = $this->discount_type === Discount::AMOUNT ? Discount::AMOUNT : Discount::PERCENT;

        return is_numeric($value) && Decimal::compare($value, '0') > 0 ? new Discount($type, $value) : Discount::none();
    }

    public function updatedDiscountValue(): void
    {
        $this->discountChanged();
    }

    public function updatedDiscountType(): void
    {
        $this->discountChanged();
    }

    /**
     * The dialog's amounts follow a new discount (the breakdown, and the amount field without invoicing).
     */
    abstract protected function discountChanged(): void;

    /**
     * @return list<array{label: string, value: string, total: bool}>
     */
    private function breakdownRows(InvoiceDraft $draft, Discount $discount): array
    {
        $money = Money::fromSettings();
        $line = $draft->lines[0];
        $rows = [['label' => __('Price'), 'value' => $money->format($line->amount), 'total' => false]];

        if (Decimal::compare($line->discountAmount, '0') > 0) {
            $rows[] = [
                'label' => $discount->isAmount() ? __('Discount') : __('Discount (:percent%)', ['percent' => Decimal::trim($discount->value)]),
                'value' => '-'.$money->format($line->discountAmount),
                'total' => false,
            ];
        }

        if ($draft->taxLines !== []) {
            $rows[] = ['label' => __('Taxable'), 'value' => $money->format($draft->taxableTotal), 'total' => false];
        }

        foreach ($draft->taxLines as $taxLine) {
            $rows[] = ['label' => $taxLine->label(), 'value' => $money->format($taxLine->amount), 'total' => false];
        }

        if (Decimal::compare($draft->roundOff, '0') !== 0) {
            $rows[] = ['label' => __('Round off'), 'value' => $money->format($draft->roundOff), 'total' => false];
        }

        $rows[] = ['label' => __('Total'), 'value' => $money->format($draft->total), 'total' => true];

        return $rows;
    }
}
