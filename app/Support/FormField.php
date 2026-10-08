<?php

namespace App\Support;

/**
 * Ids for form fields, derived from the field name so labels and error messages can point at them.
 */
final class FormField
{
    public static function id(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        return 'field-'.trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name), '-');
    }
}
