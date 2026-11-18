<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * How many digits a customer's phone number has, without the country code: App settings "Phone number digits" (the
 * longest) and "Shortest phone number" (empty: every number has the full length). India is 10 to 10; countries
 * whose mobile numbers vary in length set a range.
 */
final readonly class PhoneLength
{
    public const MAX_SETTING = 'phone_digits';

    public const MIN_SETTING = 'phone_digits_min';

    public function __construct(public int $min, public int $max) {}

    public static function current(): self
    {
        $max = (int) AppSetting::valueOf(self::MAX_SETTING, '10');
        $min = trim((string) AppSetting::valueOf(self::MIN_SETTING, ''));

        return new self($min === '' ? $max : max(1, min((int) $min, $max)), $max);
    }

    public function allows(int $length): bool
    {
        return $length >= $this->min && $length <= $this->max;
    }

    /**
     * The validation pattern for a number typed without the country code.
     */
    public function pattern(): string
    {
        return $this->min === $this->max ? '/^[0-9]{'.$this->max.'}$/' : '/^[0-9]{'.$this->min.','.$this->max.'}$/';
    }

    /**
     * The hint under a phone field, e.g. "10 digits" or "7 to 9 digits".
     */
    public function hint(): string
    {
        return $this->min === $this->max
            ? __(':digits digits', ['digits' => $this->max])
            : __(':min to :max digits', ['min' => $this->min, 'max' => $this->max]);
    }

    /**
     * The error for a number of the wrong length.
     */
    public function message(): string
    {
        return $this->min === $this->max
            ? __('Enter :digits digits.', ['digits' => $this->max])
            : __('Enter :min to :max digits.', ['min' => $this->min, 'max' => $this->max]);
    }
}
