<?php

namespace Database\Factories;

use App\Models\CustomerService;
use App\Models\CustomerServiceRenewal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerServiceRenewal>
 */
class CustomerServiceRenewalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_service_id' => CustomerService::factory(),
            'months' => 1,
            'amount' => 100,
            'old_paid_until' => now(),
            'new_paid_until' => now()->addMonth(),
        ];
    }
}
