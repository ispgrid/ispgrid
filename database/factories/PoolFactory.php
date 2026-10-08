<?php

namespace Database\Factories;

use App\Models\Pool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pool>
 */
class PoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'pool_'.fake()->unique()->numberBetween(1, 99999),
            'description' => null,
        ];
    }
}
