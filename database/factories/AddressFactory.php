<?php

namespace Database\Factories;

use App\Models\Address;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'area' => null,
            'city' => fake()->city(),
            'state' => fake()->randomElement(['Uttar Pradesh', 'Maharashtra', 'Karnataka', 'Tamil Nadu', 'Delhi']),
            'postal_code' => fake()->postcode(),
            'country' => 'India',
            'latitude' => null,
            'longitude' => null,
        ];
    }
}
