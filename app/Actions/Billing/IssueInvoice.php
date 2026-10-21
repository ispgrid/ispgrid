<?php

namespace App\Actions\Billing;

use App\Billing\AmountInWords;
use App\Billing\BillingSettings;
use App\Billing\CustomerSnapshot;
use App\Billing\InvoiceCalculator;
use App\Billing\InvoiceDraft;
use App\Billing\InvoiceNumbers;
use App\Billing\LineInput;
use App\Billing\PaymentInput;
use App\Billing\Tax\TaxContext;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoicingNotReady;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues an invoice: works out its amounts from the lines with the customer's own place of supply, takes the next
 * number of the issue date's series, copies the company, the customer and the amounts into it, and records the
 * payment taken at the same time. It joins the caller's transaction, so a recharge and its invoice are saved
 * together or not at all.
 */
final class IssueInvoice
{
    public function __construct(private readonly RecordPayment $recordPayment) {}

    /**
     * The amounts an invoice for these lines would have, as the recharge dialogs show them before issuing.
     *
     * @param  list<LineInput>  $lines
     */
    public static function draft(Account $account, array $lines, bool $roundOff, BillingSettings $settings): InvoiceDraft
    {
        $context = new TaxContext(CustomerSnapshot::of($account, $settings)['place_of_supply']);

        return (new InvoiceCalculator)->calculate($lines, $settings->tax(), $settings->taxConfig, $context, $settings->pricesIncludeTax, $roundOff, $settings->decimals);
    }

    /**
     * @param  list<LineInput>  $lines
     */
    public function handle(
        Account $account,
        array $lines,
        bool $roundOff,
        InvoiceKind $kind,
        CarbonInterface $issueDate,
        ?PaymentInput $payment = null,
        ?CarbonInterface $periodFrom = null,
        ?CarbonInterface $periodTo = null,
        ?string $validityText = null,
        ?int $rechargeId = null,
    ): Invoice {
        $settings = BillingSettings::current();

        if (! $settings->canIssue()) {
            throw new InvoicingNotReady('Invoicing is off or the company details are not filled in.');
        }

        $draft = self::draft($account, $lines, $roundOff, $settings);

        return DB::transaction(function () use ($settings, $account, $draft, $kind, $issueDate, $payment, $periodFrom, $periodTo, $validityText, $rechargeId): Invoice {
            $number = (new InvoiceNumbers($settings))->next($issueDate);
            $customer = CustomerSnapshot::of($account, $settings);
            $setup = $settings->tax();
            $grouping = (string) AppSetting::valueOf('number_grouping', 'international');

            $invoice = Invoice::query()->create([
                'number' => $number['number'],
                'series' => $number['series'],
                'sequence' => $number['sequence'],
                'account_id' => $account->id,
                'recharge_id' => $rechargeId,
                'kind' => $kind,
                'status' => InvoiceStatus::Unpaid,
                'title' => $setup->invoiceTitle(),
                'issue_date' => $issueDate,
                'due_date' => $issueDate->copy()->addDays($account->payment_terms_days),
                'terms_days' => $account->payment_terms_days,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'validity_text' => $validityText,
                'place_of_supply' => $customer['place_of_supply'],
                'tax_setup' => $setup->id(),
                'item_code_label' => $setup->itemCodeLabel(),
                'company' => array_merge($settings->company->toArray(), [
                    'logo' => $settings->logo,
                    'currency' => [
                        'code' => $settings->currencyCode,
                        'symbol' => (string) AppSetting::valueOf('currency_symbol', ''),
                        'decimals' => $settings->decimals,
                        'grouping' => $grouping,
                    ],
                ]),
                'customer' => $customer,
                'taxable_total' => $draft->taxableTotal,
                'tax_total' => $draft->taxTotal,
                'round_off' => $draft->roundOff,
                'total' => $draft->total,
                'paid_total' => '0',
                'balance' => $draft->total,
                'total_in_words' => AmountInWords::format($draft->total, $settings->currencyCode, $grouping === 'indian'),
                'user_id' => auth()->id(),
            ]);

            foreach ($draft->lines as $position => $line) {
                $invoice->lines()->create([
                    'position' => $position + 1,
                    'description' => $line->input->description,
                    'sub_description' => $line->input->subDescription,
                    'item_code' => $line->input->itemCode,
                    'quantity' => $line->input->quantity,
                    'unit_price' => $line->input->unitPrice,
                    'discount_percent' => $line->input->discountPercent,
                    'discount_amount' => $line->discountAmount,
                    'taxable' => $line->taxable,
                    'taxed' => $line->input->taxed,
                ]);
            }

            foreach ($draft->taxLines as $position => $taxLine) {
                $invoice->taxLines()->create(['position' => $position + 1, 'name' => $taxLine->name, 'rate' => $taxLine->rate, 'amount' => $taxLine->amount]);
            }

            $invoice->refreshStatus();

            activity()
                ->performedOn($account)
                ->event('invoice-issued')
                ->withChanges(['old' => [], 'attributes' => ['invoice' => $invoice->number, 'total' => $invoice->total]])
                ->log('invoice-issued');

            if ($payment !== null) {
                $this->recordPayment->handle($invoice->id, $payment);
            }

            return $invoice->refresh();
        }, attempts: 3);
    }
}
