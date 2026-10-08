<?php

namespace Database\Factories;

use App\Models\ConnectionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectionType>
 */
class ConnectionTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Type '.fake()->unique()->numberBetween(1, 99999)];
    }
}
