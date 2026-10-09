<?php

namespace App\Support;

/**
 * A MikroTik rate limit (Mikrotik-Rate-Limit), seen from the router: rx is the customer's upload.
 * Format: "up/down burst-up/burst-down threshold-up/threshold-down time/time priority min-up/min-down".
 * Every part is optional from the right; a later part needs the earlier ones, so a priority without burst is
 * written with "0/0" placeholders. Speeds are a whole number with k or M (512k, 25M).
 */
final class RateLimit
{
    public function __construct(
        public readonly string $upload,
        public readonly string $download,
        public readonly ?string $burstUpload = null,
        public readonly ?string $burstDownload = null,
        public readonly ?string $thresholdUpload = null,
        public readonly ?string $thresholdDownload = null,
        public readonly ?int $burstTime = null,
        public readonly ?int $priority = null,
        public readonly ?string $minUpload = null,
        public readonly ?string $minDownload = null,
    ) {}

    /**
     * Bits per second, or null when the text is not a speed MikroTik reads the same way.
     */
    public static function bits(?string $speed): ?int
    {
        if ($speed === null || preg_match('/^([1-9][0-9]{0,6})([kM])$/', $speed, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * ($match[2] === 'M' ? 1_000_000 : 1_000);
    }

    /**
     * What is wrong, keyed by field (upload, burst_upload, threshold_download, burst_time, priority, ...).
     * Empty when the rate can be sent.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $errors = [];
        $speeds = [
            'upload' => $this->upload,
            'download' => $this->download,
            'burst_upload' => $this->burstUpload,
            'burst_download' => $this->burstDownload,
            'threshold_upload' => $this->thresholdUpload,
            'threshold_download' => $this->thresholdDownload,
            'min_upload' => $this->minUpload,
            'min_download' => $this->minDownload,
        ];

        foreach ($speeds as $field => $speed) {
            $required = in_array($field, ['upload', 'download'], true);

            if (($speed !== null || $required) && self::bits($speed) === null) {
                $errors[$field] = __('Enter a whole number with k or M, e.g. 512k or 25M.');
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $burst = [
            'burst_upload' => $this->burstUpload,
            'burst_download' => $this->burstDownload,
            'threshold_upload' => $this->thresholdUpload,
            'threshold_download' => $this->thresholdDownload,
            'burst_time' => $this->burstTime,
        ];
        $filled = array_filter($burst, fn (string|int|null $value): bool => $value !== null);

        if ($filled !== [] && count($filled) < count($burst)) {
            foreach (array_keys(array_diff_key($burst, $filled)) as $field) {
                $errors[$field] = __('Fill in every burst field, or none of them.');
            }
        }

        if ($this->burstTime !== null && ($this->burstTime < 1 || $this->burstTime > 3600)) {
            $errors['burst_time'] = __('Enter 1 to 3600 seconds.');
        }

        if ($this->priority !== null && ($this->priority < 1 || $this->priority > 8)) {
            $errors['priority'] = __('Enter a priority from 1 (highest) to 8 (lowest).');
        }

        if (($this->minUpload === null) !== ($this->minDownload === null)) {
            $errors[$this->minUpload === null ? 'min_upload' : 'min_download'] = __('Fill in both minimum speeds, or neither.');
        }

        $directions = [
            'upload' => [$this->upload, $this->burstUpload, $this->thresholdUpload, $this->minUpload],
            'download' => [$this->download, $this->burstDownload, $this->thresholdDownload, $this->minDownload],
        ];

        foreach ($directions as $direction => [$rate, $burstRate, $threshold, $minimum]) {
            $limit = self::bits($rate);

            if ($burstRate !== null && self::bits($burstRate) < $limit) {
                $errors["burst_{$direction}"] ??= __('The burst speed must be at least the normal speed.');
            }

            if ($threshold !== null && self::bits($threshold) > $limit) {
                $errors["threshold_{$direction}"] ??= __('The threshold must not be above the normal speed.');
            }

            if ($minimum !== null && self::bits($minimum) > $limit) {
                $errors["min_{$direction}"] ??= __('The minimum speed must not be above the normal speed.');
            }
        }

        return $errors;
    }

    public function toString(): string
    {
        $parts = ["{$this->upload}/{$this->download}"];
        $hasTail = $this->priority !== null || $this->minUpload !== null;

        if ($this->burstUpload !== null) {
            $parts[] = "{$this->burstUpload}/{$this->burstDownload}";
            $parts[] = "{$this->thresholdUpload}/{$this->thresholdDownload}";
            $parts[] = "{$this->burstTime}/{$this->burstTime}";
        } elseif ($hasTail) {
            array_push($parts, '0/0', '0/0', '0/0');
        }

        if ($hasTail) {
            $parts[] = (string) ($this->priority ?? 8);
        }

        if ($this->minUpload !== null) {
            $parts[] = "{$this->minUpload}/{$this->minDownload}";
        }

        return implode(' ', $parts);
    }
}
