<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Recharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recharge>
 */
class RechargeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'plan_id' => Plan::factory(),
            'old_expiry' => now(),
            'new_expiry' => now()->addDays(30),
            'amount' => 599,
            'note' => null,
            'user_id' => null,
        ];
    }
}
