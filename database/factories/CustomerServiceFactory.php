<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CustomerService;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerService>
 */
class CustomerServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'item_id' => Item::factory()->service(),
            'ip_id' => null,
            'paid_until' => now()->addMonth(),
        ];
    }
}
