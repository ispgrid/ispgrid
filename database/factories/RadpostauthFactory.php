<?php

namespace Database\Factories;

use App\Models\Radpostauth;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Radpostauth>
 */
class RadpostauthFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->regexify('[a-z]{8}'),
            'pass' => '',
            'reply' => 'Access-Accept',
            'reply_msg' => null,
            'authdate' => now(),
            'nasipaddress' => '10.0.0.1',
            'mac' => 'BC:24:11:00:30:20',
        ];
    }

    public function rejected(string $reason = 'Wrong Password'): static
    {
        return $this->state(fn (): array => ['reply' => 'Access-Reject', 'reply_msg' => $reason]);
    }

    public function dropped(): static
    {
        return $this->state(fn (): array => ['reply' => '0', 'reply_msg' => 'Dropped: no Message-Authenticator (this router must send it)']);
    }
}
