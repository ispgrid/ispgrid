<?php

namespace App\Support\Addons;

/**
 * One installed add-on, from its composer.json "extra.ispgrid".
 */
final readonly class Addon
{
    /**
     * @param  list<string>  $supports  core series it supports, e.g. ["0.1"]
     * @param  list<string>  $providers  service provider classes
     */
    public function __construct(
        public string $package,
        public string $name,
        public string $version,
        public array $supports,
        public array $providers,
        public bool $compatible,
    ) {}
}
