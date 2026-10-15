<?php

namespace App\Support;

use Closure;

/**
 * Every box on the Roles screen: core's App\Enums\Permission cases first, then add-on permissions. Labels and
 * areas are closures so they follow the staff member's language. An add-on also inserts its permissions row in a
 * migration (guard "web").
 */
final class Permissions
{
    /**
     * @var array<string, array{label: Closure(): string, area: Closure(): string, implies: ?string}>
     */
    private array $entries = [];

    /**
     * @param  Closure(): string  $label
     * @param  Closure(): string  $area
     */
    public function register(string $name, Closure $label, Closure $area, ?string $implies = null): void
    {
        $this->entries[$name] = ['label' => $label, 'area' => $area, 'implies' => $implies];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    public function label(string $name): string
    {
        return ($this->entries[$name]['label'])();
    }

    /**
     * The boxes grouped by area, areas in the order they first appear.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public function byArea(): array
    {
        $areas = [];

        foreach ($this->entries as $name => $entry) {
            $areas[($entry['area'])()][] = ['value' => $name, 'label' => ($entry['label'])()];
        }

        return $areas;
    }

    /**
     * The given names plus the view boxes they imply, unknown names dropped.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function withImplied(array $names): array
    {
        $known = array_values(array_filter($names, $this->has(...)));

        foreach ($known as $name) {
            if ($this->entries[$name]['implies'] !== null) {
                $known[] = $this->entries[$name]['implies'];
            }
        }

        return array_values(array_unique($known));
    }

    /**
     * The given names as their labels, in screen order.
     *
     * @param  list<string>  $names
     */
    public function labels(array $names): string
    {
        return collect($this->names())
            ->filter(fn (string $name): bool => in_array($name, $names, true))
            ->map(fn (string $name): string => $this->label($name))
            ->implode(', ');
    }
}
