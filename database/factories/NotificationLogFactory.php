<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => 'fake',
            'channel' => NotificationChannel::Sms,
            'to' => '+91'.fake()->numerify('9#########'),
            'template' => ['template_id' => 'tmpl-1'],
            'values' => ['Asha', '01-Nov-2026'],
            'status' => NotificationStatus::Queued,
        ];
    }

    public function failed(string $error = 'Rejected'): static
    {
        return $this->state(['status' => NotificationStatus::Failed, 'error' => $error, 'attempts' => 5]);
    }

    public function sent(): static
    {
        return $this->state(['status' => NotificationStatus::Sent, 'provider_message_id' => 'msg-1', 'sent_at' => now(), 'attempts' => 1]);
    }
}
