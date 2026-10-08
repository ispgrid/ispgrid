<?php

namespace Database\Factories;

use App\Enums\SlotMode;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $speed = fake()->randomElement([15, 25, 50, 100]);

        return [
            'slug' => 'plan_'.fake()->unique()->numberBetween(1, 99999),
            'name' => "{$speed} Mbps",
            'validity' => 30,
            'price' => 599,
            'upload' => "{$speed}M",
            'download' => "{$speed}M",
        ];
    }

    /**
     * A plan with a 22:00 to 06:00 boost slot on every day.
     */
    public function boostSlot(): static
    {
        return $this->state([
            'slot_mode' => SlotMode::Boost,
            'slot_days' => 'Mo,Tu,We,Th,Fr,Sa,Su',
            'slot_from' => 22,
            'slot_to' => 6,
            'slot_upload' => '50M',
            'slot_download' => '50M',
        ]);
    }
}
