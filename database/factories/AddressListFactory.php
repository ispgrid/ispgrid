<?php

namespace Database\Factories;

use App\Models\AddressList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddressList>
 */
class AddressListFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'list_'.fake()->unique()->numberBetween(1, 99999),
            'description' => null,
        ];
    }
}
