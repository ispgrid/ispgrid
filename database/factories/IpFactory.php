<?php

namespace Database\Factories;

use App\Models\Ip;
use App\Models\IpRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ip>
 */
class IpFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip_range_id' => IpRange::factory(),
            'address' => fake()->unique()->ipv4(),
            'is_used' => false,
        ];
    }
}
