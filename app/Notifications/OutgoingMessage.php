<?php

namespace App\Notifications;

/**
 * Everything a provider gets for one message: one number, one approved template and its values in order.
 */
final readonly class OutgoingMessage
{
    /**
     * @param  string  $to  E.164, e.g. +919876543210
     * @param  array<string, string>  $template  the rule's template fields (MSG91: template_id; wacrm: name, language)
     * @param  list<string>  $values  the template parameters, in order, each at most 30 characters
     * @param  ?string  $preview  the approved text with the values filled in, for the log only; never sent
     * @param  string  $reference  the notification_log id, for providers that accept a client reference
     */
    public function __construct(
        public string $to,
        public array $template,
        public array $values,
        public ?string $preview,
        public string $reference,
    ) {}
}
