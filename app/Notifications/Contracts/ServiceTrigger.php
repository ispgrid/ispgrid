<?php

namespace App\Notifications\Contracts;

use App\Models\CustomerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A scheduled trigger about a customer service (a static IP, OTT), not the internet plan: due per service, so a
 * customer with two services expiring the same day gets two messages. Its due() returns an empty query.
 */
interface ServiceTrigger extends Trigger
{
    /**
     * The services due now. Only a query: the core adds the rule's filters and the "No reminders" switch through
     * the service's customer.
     *
     * @param  array<string, mixed>  $settings
     * @return Builder<CustomerService>
     */
    public function dueServices(array $settings, CarbonImmutable $now): Builder;

    /** Unique per message that must go out only once: per rule, service and paid-until date. */
    public function serviceOnceKey(int $ruleId, CustomerService $service): string;
}
