<?php

namespace Database\Factories;

use App\Enums\OnlinePaymentStatus;
use App\Models\Account;
use App\Models\OnlinePayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OnlinePayment>
 */
class OnlinePaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => strtoupper((string) Str::ulid()),
            'account_id' => Account::factory(),
            'gateway' => 'fake',
            'amount' => '590.00',
            'dues_amount' => '590.00',
            'renewal_amount' => '0.00',
            'currency' => 'INR',
            'status' => OnlinePaymentStatus::Pending,
        ];
    }
}
