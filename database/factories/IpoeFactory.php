<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Ipoe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ipoe>
 */
class IpoeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory()->ipoe(),
            'username' => fn (array $attributes) => Account::query()->findOrFail($attributes['account_id'])->username,
            'ip_address' => fake()->unique()->ipv4(),
        ];
    }
}
