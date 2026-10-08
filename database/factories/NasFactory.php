<?php

namespace Database\Factories;

use App\Models\Nas;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Nas>
 */
class NasFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nasname' => fake()->unique()->ipv4(),
            'shortname' => 'router-'.fake()->unique()->numberBetween(1, 9999),
            'type' => 'mikrotik',
            'secret' => Str::random(24),
            'description' => null,
            'routeros_version' => '7.23.7',
            'require_message_auth' => true,
        ];
    }

    /**
     * A router older than RouterOS 7.15 / 6.49.18 (no Message-Authenticator).
     */
    public function legacy(): static
    {
        return $this->state(['routeros_version' => '6.49.17', 'require_message_auth' => false]);
    }
}
