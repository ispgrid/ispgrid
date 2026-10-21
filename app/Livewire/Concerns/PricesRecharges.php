<?php

namespace App\Livewire\Concerns;

use App\Billing\BillingSettings;
use App\Billing\ChargeOptions;
use App\Billing\Decimal;
use App\Billing\InvoiceDraft;
use App\Billing\InvoicePolicy;
use App\Billing\PaymentInput;
use App\Billing\RechargeInvoice;
use App\Models\Account;
use App\Models\Plan;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;

/**
 * The money side of the recharge dialogs: a Free customer pays nothing; an invoiced recharge shows the invoice
 * breakdown, with "Adjust price" (a one-off price and its reason) for staff with Set customer pricing; otherwise the
 * amount stays an editable field, as before invoicing.
 */
trait PricesRecharges
{
    public string $adjust_price = '';

    public string $adjust_reason = '';

    protected function resetAdjust(): void
    {
        $this->adjust_price = '';
        $this->adjust_reason = '';
    }

    /**
     * What the dialog shows for this plan: mode 'free', 'invoice' (with the breakdown rows and the total) or
     * 'amount' (the editable amount).
     *
     * @return array{mode: string, rows: list<array{label: string, value: string, total: bool}>, total: string, can_adjust: bool}
     */
    protected function chargeSummary(Account $account, ?Plan $plan): array
    {
        $settings = BillingSettings::current();
        $mode = $account->billing_free ? 'free' : (InvoicePolicy::invoicesRecharge($account, $settings) ? 'invoice' : 'amount');
        $draft = $mode === 'invoice' && $plan !== null ? $this->chargeDraft($account, $plan, $settings) : null;

        return [
            'mode' => $mode,
            'rows' => $draft === null ? [] : $this->breakdownRows($draft, (string) $account->discount_percent),
            'total' => $draft === null ? '0' : $draft->total,
            'can_adjust' => Gate::allows('set-customer-pricing'),
        ];
    }

    protected function chargeDraft(Account $account, Plan $plan, BillingSettings $settings): InvoiceDraft
    {
        return RechargeInvoice::draft($account, $plan, $settings, $this->adjustedPrice(), null, null, null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function adjustRules(): array
    {
        if (trim($this->adjust_price) === '') {
            return [];
        }

        abort_unless(Gate::allows('set-customer-pricing'), 403);

        return [
            'adjust_price' => ['regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'adjust_reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function adjustAttributes(): array
    {
        return ['adjust_price' => __('price for this recharge'), 'adjust_reason' => __('reason')];
    }

    protected function chargeOptions(?PaymentInput $payment): ChargeOptions
    {
        $adjusted = $this->adjustedPrice();

        return new ChargeOptions($adjusted, $adjusted === null ? null : trim($this->adjust_reason), $payment);
    }

    /**
     * The adjusted price when one is typed and valid, otherwise null (the plan price).
     */
    private function adjustedPrice(): ?string
    {
        $price = trim($this->adjust_price);

        return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $price) === 1 ? $price : null;
    }

    /**
     * @return list<array{label: string, value: string, total: bool}>
     */
    private function breakdownRows(InvoiceDraft $draft, string $discountPercent): array
    {
        $money = Money::fromSettings();
        $line = $draft->lines[0];
        $rows = [['label' => __('Price'), 'value' => $money->format($line->amount), 'total' => false]];

        if (Decimal::compare($line->discountAmount, '0') > 0) {
            $rows[] = ['label' => __('Discount (:percent%)', ['percent' => Decimal::trim($discountPercent)]), 'value' => '-'.$money->format($line->discountAmount), 'total' => false];
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
