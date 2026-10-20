<?php

namespace Database\Factories;

use App\Enums\AdvanceRenewalStatus;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvanceRenewal>
 */
class AdvanceRenewalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'plan_id' => Plan::factory(),
            'amount' => 599,
            'starts_at' => now()->addDays(10),
            'expiry' => now()->addDays(40),
            'note' => null,
            'user_id' => null,
            'status' => AdvanceRenewalStatus::Waiting,
        ];
    }
}
