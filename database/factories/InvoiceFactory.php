<?php

namespace Database\Factories;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An unpaid manual invoice of 100.00 with no tax.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'INV-'.fake()->unique()->numberBetween(1000, 999999),
            'series' => 'all',
            'sequence' => fn (array $attributes): int => (int) preg_replace('/\D/', '', (string) $attributes['number']),
            'account_id' => Account::factory(),
            'kind' => InvoiceKind::Manual,
            'status' => InvoiceStatus::Unpaid,
            'title' => 'INVOICE',
            'issue_date' => now(),
            'due_date' => now(),
            'terms_days' => 0,
            'tax_setup' => 'none',
            'company' => ['name' => 'Example ISP', 'country' => 'GB'],
            'customer' => ['username' => 'example', 'name' => 'Example customer', 'billing_lines' => [], 'connectivity_lines' => []],
            'taxable_total' => '100.00',
            'tax_total' => '0.00',
            'round_off' => '0.00',
            'total' => '100.00',
            'paid_total' => '0.00',
            'balance' => '100.00',
            'total_in_words' => 'One Hundred Only',
        ];
    }
}
