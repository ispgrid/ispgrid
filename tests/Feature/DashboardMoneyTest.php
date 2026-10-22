<?php

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RemovePayment;
use App\Billing\PaymentInput;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Recharge;
use App\Models\User;
use App\Support\Dashboard\MoneyFigures;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    $this->boss = staffHolding(...Permission::cases());
    $this->actingAs($this->boss);
});

/**
 * The money cards by label: value and hint.
 *
 * @return array<string, array{value: string, href: ?string, hint: ?string}>
 */
function moneyCards(User $user): array
{
    return collect(MoneyFigures::cards($user))
        ->mapWithKeys(fn (array $card): array => [$card['label'] => ['value' => $card['value'], 'href' => $card['href'], 'hint' => $card['hint']]])
        ->all();
}

function moneyInvoice(string $price, ?string $paid = null, string $method = 'Cash'): Invoice
{
    $payment = $paid === null ? null : new PaymentInput(PaymentMethod::query()->where('name', $method)->sole()->id, $paid, null, now(), null);

    return app(IssueInvoice::class)->handle(Account::factory()->create(), billingLines($price), false, InvoiceKind::Manual, now(), $payment);
}

it('shows collected, invoiced and dues', function () {
    turnInvoicingOn();
    $this->travelTo(Carbon::parse('2026-09-30 23:00:00'));
    moneyInvoice('200', '236');
    $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
    moneyInvoice('100', '118');
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    moneyInvoice('500', '590', 'UPI');
    moneyInvoice('1000', '500');
    $removed = moneyInvoice('50', '59');
    app(RemovePayment::class)->handle($removed->payments()->sole()->id, 'Bounced');
    app(CancelInvoice::class)->handle(moneyInvoice('9000')->id, 'Wrong');

    $cards = moneyCards($this->boss);

    expect(array_keys($cards))->toBe(['Collected today', 'Collected this month', 'Collected last month', 'Invoiced this month', 'Invoiced last month', 'Dues'])
        ->and($cards['Collected today']['value'])->toBe('1,090.00')
        ->and($cards['Collected this month']['value'])->toBe('1,208.00')
        ->and($cards['Collected this month']['hint'])->toBe('Cash 618.00, UPI 590.00')
        ->and($cards['Collected last month']['value'])->toBe('236.00')
        ->and($cards['Invoiced this month']['value'])->toBe('1,947.00')
        ->and($cards['Invoiced last month']['value'])->toBe('236.00')
        ->and($cards['Dues']['value'])->toBe('739.00')
        ->and($cards['Dues']['href'])->toBe(route('customers.index', ['dues' => 1]));
});

it('shows sales from recharges when invoicing is off', function () {
    Recharge::factory()->create(['amount' => 500]);
    Recharge::factory()->create(['amount' => 250, 'created_at' => now()->subDays(3)]);
    Recharge::factory()->create(['amount' => 99, 'created_at' => now()->subMonth()]);
    Recharge::factory()->create(['amount' => 40, 'created_at' => now()->subMonths(2)]);

    $cards = moneyCards($this->boss);

    expect(array_keys($cards))->toBe(['Sales today', 'Sales this month', 'Sales last month'])
        ->and($cards['Sales today']['value'])->toBe('500.00')
        ->and($cards['Sales this month']['value'])->toBe('750.00')
        ->and($cards['Sales last month']['value'])->toBe('99.00');
});

it('lists the top 5 plans by sales this month, with their recharges', function () {
    $plans = collect(['Basic', 'Silver', 'Gold', 'Gold Plus', 'Fibre 200', 'Fibre 500'])
        ->mapWithKeys(fn (string $name): array => [$name => Plan::factory()->create(['name' => $name])]);
    $sell = fn (string $plan, string $amount, array $extra = []) => Recharge::factory()->create(['plan_id' => $plans[$plan]->id, 'amount' => $amount, ...$extra]);

    $sell('Gold', '500');
    $sell('Gold', '500');
    $sell('Silver', '300');
    $sell('Basic', '100');
    $sell('Fibre 200', '800');
    $sell('Fibre 500', '50');
    $sell('Gold Plus', '200');
    $sell('Basic', '5000', ['created_at' => now()->subMonth()]);
    $sell('Silver', '900', ['account_id' => Account::factory()->create(['billing_free' => true])->id]);
    $plans['Gold']->delete();

    expect(MoneyFigures::topPlans($this->boss))->toBe([
        ['plan' => 'Gold', 'recharges' => 2, 'amount' => '1,000.00'],
        ['plan' => 'Fibre 200', 'recharges' => 1, 'amount' => '800.00'],
        ['plan' => 'Silver', 'recharges' => 1, 'amount' => '300.00'],
        ['plan' => 'Gold Plus', 'recharges' => 1, 'amount' => '200.00'],
        ['plan' => 'Basic', 'recharges' => 1, 'amount' => '100.00'],
    ])->and(MoneyFigures::topPlans(staffHolding(Permission::ViewCustomers)))->toBe([]);

    Livewire::test('pages::dashboard')->assertSee(__('Sales per plan, this month'))->assertSee('Fibre 200');
});

it('leaves out Free customers', function () {
    Recharge::factory()->create(['amount' => 500]);
    Recharge::factory()->create(['amount' => 300, 'account_id' => Account::factory()->create(['billing_free' => true])->id]);

    expect(moneyCards($this->boss)['Sales today']['value'])->toBe('500.00');
});

it('needs View sales', function () {
    turnInvoicingOn();
    $staff = staffHolding(Permission::ViewCustomers, Permission::ViewInvoices);

    expect(MoneyFigures::cards($staff))->toBe([]);

    $this->actingAs($staff);
    Livewire::test('pages::dashboard')->assertDontSee(__('Collected today'));
    $this->actingAs($this->boss);
    Livewire::test('pages::dashboard')->assertSee(__('Collected today'));
});

it('counts uninvoiced Hotspot recharges as Hotspot sales', function () {
    turnInvoicingOn();

    expect(moneyCards($this->boss))->not->toHaveKey('Hotspot sales this month');

    AppSetting::put('billing.invoice_hotspot', '0');
    Recharge::factory()->create(['amount' => 30, 'account_id' => Account::factory()->hotspot()->create()->id]);
    Recharge::factory()->create(['amount' => 20, 'account_id' => Account::factory()->hotspot()->create()->id]);

    expect(moneyCards($this->boss)['Hotspot sales this month']['value'])->toBe('50.00');
});
