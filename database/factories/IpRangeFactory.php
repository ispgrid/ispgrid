<?php

namespace Database\Factories;

use App\Models\IpRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpRange>
 */
class IpRangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $octet = fake()->unique()->numberBetween(1, 250);

        return [
            'name' => 'Range '.$octet,
            'network' => "10.{$octet}.0.0/24",
            'gateway' => "10.{$octet}.0.1",
            'dns1' => '1.1.1.1',
            'dns2' => '8.8.8.8',
        ];
    }
}
