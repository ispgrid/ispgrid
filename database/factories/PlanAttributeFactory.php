<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanAttribute>
 */
class PlanAttributeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'attribute' => 'Idle-Timeout',
            'op' => '=',
            'value' => '300',
        ];
    }
}
