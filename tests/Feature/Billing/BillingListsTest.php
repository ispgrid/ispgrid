<?php

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RemovePayment;
use App\Billing\PaymentInput;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    turnInvoicingOn();
    $this->actingAs(staffHolding(...Permission::cases()));
});

function listInvoice(Account $account, string $price, ?string $paid = null, string $method = 'Cash'): Invoice
{
    $payment = $paid === null ? null : new PaymentInput(PaymentMethod::query()->where('name', $method)->sole()->id, $paid, 'REF-'.$price, now(), null);

    return app(IssueInvoice::class)->handle($account, billingLines($price), false, InvoiceKind::Manual, now(), $payment);
}

/**
 * The CSV a Livewire download action streamed, as rows.
 *
 * @return list<list<string>>
 */
function csvRows(string $content): array
{
    return array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", str_replace("\r", '', $content)))));
}

it('lists invoices with a totals row', function () {
    listInvoice(Account::factory()->create(['username' => 'alice']), '500', '590');
    listInvoice(Account::factory()->create(['username' => 'bob']), '1000');
    $cancelled = listInvoice(Account::factory()->create(), '100');
    app(CancelInvoice::class)->handle($cancelled->id, 'Wrong');

    $page = Livewire::test('pages::invoices.index')
        ->assertSee('INV-1')
        ->assertSee('INV-2')
        ->assertSee('INV-3');

    expect($page->instance()->totals)->toMatchArray(['total' => '1,770.00', 'paid' => '590.00', 'balance' => '1,180.00']);

    $page->set('search', 'alice')
        ->assertSee('INV-1')
        ->assertDontSee('INV-2')
        ->set('search', '')
        ->set('status', 'unpaid')
        ->assertDontSee('INV-1')
        ->assertSee('INV-2');
});

it('filters invoices by period and by payment method', function () {
    listInvoice(Account::factory()->create(), '500', '590', 'UPI');
    $this->travel(2)->days();
    listInvoice(Account::factory()->create(), '700', '826');
    $upi = PaymentMethod::query()->where('name', 'UPI')->sole();

    Livewire::test('pages::invoices.index')
        ->set('method', (string) $upi->id)
        ->assertSee('INV-1')
        ->assertDontSee('INV-2')
        ->set('method', '')
        ->set('from', '2026-10-22')
        ->assertDontSee('INV-1')
        ->assertSee('INV-2');
});

it('exports the GST columns', function () {
    listInvoice(Account::factory()->create(['username' => 'home', 'fullname' => 'Home Customer']), '500');
    listInvoice(Account::factory()->create(['username' => 'delhi', 'company' => 'Delhi Co', 'tax_id' => '07ABCDE1234F1Z5', 'place_of_supply' => '07']), '1000');

    $download = Livewire::test('pages::invoices.index')->call('export');
    $rows = csvRows((string) base64_decode($download->effects['download']['content']));

    expect($rows[0])->toBe(['Number', 'Date', 'Customer', 'Username', 'GSTIN', 'Place of supply', 'Taxable', 'CGST', 'SGST', 'IGST', 'Other tax', 'Round off', 'Total', 'Paid', 'Balance', 'Status'])
        ->and($rows[1])->toBe(['INV-1', '2026-10-21', 'Home Customer', 'home', '', 'Uttar Pradesh (09)', '500.00', '45.00', '45.00', '0.00', '0.00', '0.00', '590.00', '0.00', '590.00', 'Unpaid'])
        ->and($rows[2])->toBe(['INV-2', '2026-10-21', 'Delhi Co', 'delhi', '07ABCDE1234F1Z5', 'Delhi (07)', '1000.00', '0.00', '0.00', '180.00', '0.00', '0.00', '1180.00', '0.00', '1180.00', 'Unpaid']);
});

it('keeps a formula out of the CSV', function () {
    listInvoice(Account::factory()->create(['username' => 'evil', 'fullname' => '=HYPERLINK("x")']), '500');

    $download = Livewire::test('pages::invoices.index')->call('export');

    expect(csvRows((string) base64_decode($download->effects['download']['content']))[1][2])->toBe("'=HYPERLINK(\"x\")");
});

it('needs View sales for the export and the totals', function () {
    listInvoice(Account::factory()->create(), '500');
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices));

    $page = Livewire::test('pages::invoices.index')->assertSee('INV-1');

    expect($page->instance()->totals)->toBeNull();
    $page->call('export')->assertForbidden();

    Livewire::test('pages::payments.index')->call('export')->assertForbidden();
});

it('totals payments per method', function () {
    $first = listInvoice(Account::factory()->create(), '500', '590');
    listInvoice(Account::factory()->create(), '1000', '1180', 'UPI');
    app(RecordPayment::class)->handle(listInvoice(Account::factory()->create(), '100')->id,
        new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '18', null, now(), null));
    $removed = $first->payments()->sole();
    app(RemovePayment::class)->handle($removed->id, 'Bounced');

    $page = Livewire::test('pages::payments.index')
        ->assertSee('REF-1000')
        ->assertSee('Bounced');

    expect($page->instance()->methodTotals)->toBe(['Cash' => '18.00', 'UPI' => '1,180.00'])
        ->and(csvRows((string) base64_decode($page->call('export')->effects['download']['content']))[0])
        ->toBe(['Date', 'Invoice', 'Customer', 'Method', 'Reference', 'Amount', 'Recorded by', 'Removed']);
});

it('filters customers with dues', function () {
    $owes = Account::factory()->create(['username' => 'owes']);
    $paid = Account::factory()->create(['username' => 'paidup']);
    listInvoice($owes, '500');
    listInvoice($paid, '500', '590');

    Livewire::withQueryParams(['dues' => '1'])->test('pages::customers.index')
        ->assertSee('owes')
        ->assertDontSee('paidup')
        ->assertSee(__('Has dues'));
});

it('hides the Billing menu when invoicing is off and nothing was issued', function () {
    $admin = staffHolding(...Permission::cases());
    AppSetting::put('billing.enabled', '0');

    $this->actingAs($admin)->get(route('dashboard'))->assertDontSee(route('invoices.index'));

    AppSetting::put('billing.enabled', '1');
    listInvoice(Account::factory()->create(), '1');
    AppSetting::put('billing.enabled', '0');

    $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('invoices.index'))->assertSee(route('payments.index'));
    $this->actingAs(staffHolding(Permission::ViewCustomers))->get(route('invoices.index'))->assertForbidden();
});
