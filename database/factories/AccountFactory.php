<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => 'user'.fake()->unique()->numberBetween(1000, 999999),
            'password' => fake()->regexify('[A-Za-z0-9]{10}'),
            'fullname' => fake()->name(),
            'mobile' => fake()->numerify('9#########'),
            'service_type' => ServiceType::Ppp,
            'mac_type' => 1,
            'plan_id' => Plan::factory(),
            'status_id' => AccountStatus::Active,
            'expiry' => now()->addDays(30),
        ];
    }

    public function ipoe(): static
    {
        return $this->state(['service_type' => ServiceType::Ipoe, 'password' => null, 'mac_type' => 1]);
    }

    /**
     * A STATIC leased line, managed on the router and not written to FreeRADIUS.
     */
    public function leasedLine(): static
    {
        return $this->state(['service_type' => ServiceType::Static, 'password' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['status_id' => AccountStatus::Inactive]);
    }

    public function suspended(): static
    {
        return $this->state(['status_id' => AccountStatus::Suspended]);
    }

    public function terminated(): static
    {
        return $this->state(['status_id' => AccountStatus::Terminated]);
    }

    public function expired(): static
    {
        return $this->state(['expiry' => now()->subDay()]);
    }
}
