<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one notification_log row through its provider (Task 6).
 */
class SendNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $logId) {}

    public function handle(): void {}
}
