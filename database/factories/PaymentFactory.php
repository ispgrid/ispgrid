<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'account_id' => fn (array $attributes): int => (int) Invoice::query()->whereKey($attributes['invoice_id'])->value('account_id'),
            'payment_method_id' => fn (): int => PaymentMethod::query()->orderBy('position')->value('id') ?? PaymentMethod::factory()->create()->id,
            'amount' => '100.00',
            'paid_at' => now(),
        ];
    }
}
