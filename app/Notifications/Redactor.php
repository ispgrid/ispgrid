<?php

namespace App\Notifications;

/**
 * Takes API keys out of any text before it is stored or shown (an exception message can carry a URL or header).
 */
final class Redactor
{
    /**
     * @param  list<string>  $secrets
     */
    public static function scrub(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (mb_strlen($secret) >= 4) {
                $text = str_replace($secret, '••••', $text);
            }
        }

        return $text;
    }
}
