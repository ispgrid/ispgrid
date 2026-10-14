<?php

namespace App\Console\Commands;

use App\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:dispatch')]
#[Description('Queue the expiry reminders that are due now')]
class DispatchNotifications extends Command
{
    public function handle(Notifier $notifier): int
    {
        $count = $notifier->dispatchScheduled(CarbonImmutable::now());

        $this->info(trans_choice('Queued :count message.|Queued :count messages.', $count));

        return self::SUCCESS;
    }
}
