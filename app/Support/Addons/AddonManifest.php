<?php

namespace App\Support\Addons;

/**
 * The installed add-ons: Composer packages whose composer.json has "extra.ispgrid" (name, core, providers). An
 * add-on is compatible when it lists the running core's series (major.minor of the installed tag).
 */
final class AddonManifest
{
    /**
     * @var list<Addon>|null
     */
    private ?array $addons = null;

    public function __construct(private readonly string $installedJson, private readonly string $coreVersion) {}

    /**
     * "0.1" for v0.1.0 or v0.1.0-rc6; null for a development checkout (no version tag).
     */
    public static function series(string $version): ?string
    {
        return preg_match('/^v?(\d+)\.(\d+)\./', $version, $match) === 1 ? "{$match[1]}.{$match[2]}" : null;
    }

    public function coreSeries(): ?string
    {
        return self::series($this->coreVersion);
    }

    /**
     * @return list<Addon>
     */
    public function all(): array
    {
        return $this->addons ??= $this->read();
    }

    /**
     * @return list<Addon>
     */
    public function compatible(): array
    {
        return array_values(array_filter($this->all(), fn (Addon $addon): bool => $addon->compatible));
    }

    /**
     * @return list<Addon>
     */
    private function read(): array
    {
        if (! is_file($this->installedJson)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->installedJson), true);
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
        $series = $this->coreSeries();
        $addons = [];

        foreach ($packages as $package) {
            $extra = $package['extra']['ispgrid'] ?? null;

            if (! is_array($extra) || ! isset($package['name'])) {
                continue;
            }

            $supports = array_values(array_map(fn (mixed $core): string => (string) $core, (array) ($extra['core'] ?? [])));

            $addons[] = new Addon(
                package: (string) $package['name'],
                name: (string) ($extra['name'] ?? $package['name']),
                version: (string) ($package['version'] ?? ''),
                supports: $supports,
                providers: array_values(array_map(fn (mixed $provider): string => (string) $provider, (array) ($extra['providers'] ?? []))),
                compatible: $series === null || in_array($series, $supports, true),
            );
        }

        usort($addons, fn (Addon $a, Addon $b): int => strcmp($a->name, $b->name));

        return $addons;
    }
}
