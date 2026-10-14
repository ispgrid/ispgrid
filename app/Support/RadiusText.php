<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The last check before a value reaches a FreeRADIUS table. FreeRADIUS escapes every character outside its safe
 * set before it looks a value up (safe_characters, deploy/freeradius/configure-sql.py), so Hindi, accented letters
 * or a line break would be stored and then never match. The forms already refuse such text; this throws only when
 * some other path lets one through, and the save's transaction rolls back.
 */
final class RadiusText
{
    /**
     * English letters, digits, symbols and spaces only.
     */
    public static function isEnglish(string $value): bool
    {
        return preg_match('/^[\x20-\x7E]*$/D', $value) === 1;
    }

    /**
     * @param  iterable<int|string, mixed>  $values  one row (column => value) or a list of rows
     *
     * @throws InvalidArgumentException
     */
    public static function check(string $table, iterable $values): void
    {
        foreach ($values as $column => $value) {
            if (is_iterable($value)) {
                self::check($table, $value);
            } elseif (is_string($value) && ! self::isEnglish($value)) {
                throw new InvalidArgumentException(
                    "FreeRADIUS cannot read {$table}.{$column} = \"{$value}\": use English letters, digits and symbols only."
                );
            }
        }
    }
}
