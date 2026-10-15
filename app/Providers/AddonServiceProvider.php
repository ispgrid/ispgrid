<?php

namespace App\Providers;

use App\Support\Addons\AddonManifest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Loads the installed add-ons that support this core version (config/ispgrid.php). Add-ons are not discovered by
 * Laravel: they list their providers in "extra.ispgrid.providers", so an incompatible one is never booted.
 */
class AddonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AddonManifest::class, fn (): AddonManifest => new AddonManifest(
            base_path('vendor/composer/installed.json'),
            (string) config('ispgrid.version'),
        ));

        if (config('ispgrid.addons')) {
            $this->registerAddons($this->app->make(AddonManifest::class));
        }
    }

    public function boot(): void
    {
        if (! config('ispgrid.addons')) {
            return;
        }

        $manifest = $this->app->make(AddonManifest::class);

        foreach ($manifest->all() as $addon) {
            if (! $addon->compatible) {
                Log::warning("Add-on {$addon->package} {$addon->version} supports core ".implode(', ', $addon->supports).", this is {$manifest->coreSeries()}: not loaded.");
            }
        }
    }

    public function registerAddons(AddonManifest $manifest): void
    {
        foreach ($manifest->compatible() as $addon) {
            foreach ($addon->providers as $provider) {
                if (class_exists($provider)) {
                    $this->app->register($provider);
                }
            }
        }
    }
}
