<?php

namespace Database\Factories;

use App\Models\NotificationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRule>
 */
class NotificationRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Rule '.fake()->unique()->numberBetween(1, 999999),
            'trigger' => 'before_expiry',
            'trigger_settings' => ['days' => 3],
            'filters' => [],
            'provider' => 'fake',
            'template' => ['template_id' => 'tmpl-1'],
            'values' => ['customer_name', 'expiry_date'],
            'approved_text' => 'Dear {1}, your plan expires on {2}.',
            'enabled' => false,
        ];
    }

    public function enabled(): static
    {
        return $this->state(['enabled' => true]);
    }
}
