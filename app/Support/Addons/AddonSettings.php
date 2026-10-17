<?php

namespace App\Support\Addons;

use App\Models\User;

/**
 * Add-on settings on the System Add-ons page: each add-on registers one Livewire component, shown under its entry to
 * staff with the add-on's own permission. Settings are stored with AppSetting under keys named for the add-on
 * ("ipdr.keep_days").
 */
final class AddonSettings
{
    /**
     * @var array<string, array{component: string, ability: string}>
     */
    private array $sections = [];

    /**
     * @param  string  $package  the add-on's Composer package, e.g. "ispgrid/ipdr"
     * @param  string  $component  the Livewire component name, e.g. "ipdr::settings"
     * @param  string  $ability  the permission that shows and allows the section
     */
    public function register(string $package, string $component, string $ability): void
    {
        $this->sections[$package] = ['component' => $component, 'ability' => $ability];
    }

    /**
     * @return array<string, array{component: string, ability: string}>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * The page lists the add-ons for App settings staff, and each section for staff with its permission.
     */
    public function canOpenPage(User $user): bool
    {
        if ($user->can('manage-app-settings')) {
            return true;
        }

        foreach ($this->sections as $section) {
            if ($user->can($section['ability'])) {
                return true;
            }
        }

        return false;
    }
}
