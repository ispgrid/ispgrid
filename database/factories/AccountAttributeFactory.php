<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountAttribute>
 */
class AccountAttributeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'attribute' => 'Framed-Route',
            'op' => '+=',
            'value' => '203.0.113.8/29 0.0.0.0 1',
        ];
    }
}
