<?php

/*
 * The installer's add-on helper: reads the ISPGrid add-on list (registry.json from github.com/ispgrid/addons) and
 * the server's addons.json. Plain PHP without Composer (it runs before the panel's code is installed), and it must
 * run on PHP 8.3 (the CI runner's system PHP). Every value from the registry is checked before it is used: a
 * registry with one value that is not valid is refused as a whole.
 *
 *   php addons.php validate REGISTRY                        exit 0, or 6 when it is missing or not valid
 *   php addons.php pick REGISTRY NAME SERIES [VERSION]      "VERSION COMMIT"; exit 2 not listed, 3 no such version,
 *                                                           4 yanked, 5 no version for the series, 6 not valid
 *   php addons.php yanked REGISTRY NAME VERSION             the reason, or nothing
 *   php addons.php info REGISTRY NAME                       "OFFICIAL|AUTHOR|DESCRIPTION" (OFFICIAL yes or no)
 *   php addons.php available REGISTRY SERIES                "NAME VERSION" for each add-on with a version for SERIES
 *   php addons.php resolve ADDONS_JSON REGISTRY|- SERIES ALLOW_UNLISTED [NAME[@VERSION]...]
 *                                                           the new addons.json; "warn ..." / "error ..." on stderr
 *   php addons.php reference INSTALLED_JSON NAME            the commit Composer installed
 */

const NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/';
const VERSION_PATTERN = '/^v[0-9]+\.[0-9]+\.[0-9]+(-rc[0-9]+)?$/';
const COMMIT_PATTERN = '/^[0-9a-f]{40}$/';
const SERIES_PATTERN = '/^[0-9]+\.[0-9]+$/';
const CONSTRAINT_PATTERN = '/^[A-Za-z0-9.^~*|,<>=-]+$/';
const REGISTRY_URL = 'https://github.com/ispgrid/addons';

/**
 * Stops with an exit code and an optional line on stderr.
 */
function stop(int $code, string $line = ''): never
{
    if ($line !== '') {
        fwrite(STDERR, $line."\n");
    }
    exit($code);
}

/**
 * One line of at most 200 characters without control characters.
 */
function plainText(mixed $text): bool
{
    return is_string($text) && $text !== '' && mb_strlen($text) <= 200 && ! preg_match('/[\x00-\x1f\x7f]/u', $text);
}

/**
 * The registry, or null when it is missing or any value in it is not valid.
 *
 * @return array<string, array{description: string, author: string, official: bool, versions: array<string, array{commit: string, core: list<string>, yanked?: string}>}>|null
 */
function registry(string $file): ?array
{
    $json = is_file($file) ? file_get_contents($file) : false;
    $registry = $json === false ? null : json_decode($json, true);
    if (! is_array($registry) || ($registry['schema'] ?? null) !== 1 || ! is_array($registry['addons'] ?? null)) {
        return null;
    }
    foreach ($registry['addons'] as $name => $addon) {
        if (! preg_match(NAME_PATTERN, (string) $name) || ! is_array($addon) || ! plainText($addon['description'] ?? null)
            || ! plainText($addon['author'] ?? null) || ! is_bool($addon['official'] ?? null)
            || ! is_array($addon['versions'] ?? null)) {
            return null;
        }
        foreach ($addon['versions'] as $version => $entry) {
            $core = $entry['core'] ?? null;
            if (! preg_match(VERSION_PATTERN, (string) $version) || ! is_array($entry)
                || ! is_string($entry['commit'] ?? null) || ! preg_match(COMMIT_PATTERN, $entry['commit'])
                || ! is_array($core) || $core === [] || ! array_is_list($core)
                || array_filter($core, fn ($series): bool => ! is_string($series) || ! preg_match(SERIES_PATTERN, $series)) !== []
                || (array_key_exists('yanked', $entry) && ! plainText($entry['yanked']))) {
                return null;
            }
        }
    }

    return $registry['addons'];
}

/**
 * The registry, or exit 6.
 *
 * @return array<string, mixed>
 */
function registryOrStop(string $file): array
{
    return registry($file) ?? stop(6);
}

/**
 * The version a server should install: the given one, or the newest that is not yanked and supports the series.
 * Returns [version, commit] or the exit code that says why there is none.
 *
 * @param  array<string, mixed>  $registry
 * @return array{0: string, 1: string}|int
 */
function pick(array $registry, string $name, string $series, ?string $version = null): array|int
{
    $versions = $registry[$name]['versions'] ?? null;
    if ($versions === null) {
        return 2;
    }
    if ($version !== null) {
        $entry = $versions[$version] ?? null;
        if ($entry === null) {
            return 3;
        }
        if (isset($entry['yanked'])) {
            return 4;
        }

        return in_array($series, $entry['core'], true) ? [$version, $entry['commit']] : 5;
    }
    $best = null;
    foreach ($versions as $tag => $entry) {
        if (! isset($entry['yanked']) && in_array($series, $entry['core'], true)
            && ($best === null || version_compare(substr($tag, 1), substr($best, 1), '>'))) {
            $best = $tag;
        }
    }

    return $best === null ? 5 : [$best, $versions[$best]['commit']];
}

/**
 * The new addons.json for a run: requested add-ons added (NAME unpinned, NAME@VERSION pinned), entries from v0.1.2
 * and older converted, unpinned entries moved to their newest version. $registry is null when it could not be read.
 *
 * @param  array<string, mixed>  $addons
 * @param  array<string, mixed>|null  $registry
 * @param  list<string>  $specs
 * @return array<string, mixed>
 */
function resolve(array $addons, ?array $registry, string $series, bool $allowUnlisted, array $specs): array
{
    $requested = [];
    foreach ($specs as $spec) {
        [$name, $version] = array_pad(explode('@', $spec, 2), 2, null);
        if (! preg_match(NAME_PATTERN, $name) || ($version !== null && ! preg_match(CONSTRAINT_PATTERN, $version))) {
            stop(1, "error Not an add-on name: $spec");
        }
        if ($registry === null) {
            stop(1, 'error The ISPGrid add-on list could not be read, so nothing can be added or updated. Check that this server reaches github.com.');
        }
        if (! isset($registry[$name])) {
            if (! $allowUnlisted) {
                stop(1, "error $name is not in the ISPGrid add-on list (".REGISTRY_URL.'). To install it anyway, without review: --allow-unlisted');
            }
            $addons[$name] = ['constraint' => $version ?? '*', 'unlisted' => true];

            continue;
        }
        if ($version !== null) {
            $picked = pick($registry, $name, $series, $version);
            //  A version that is not listed (a branch, or a release not reviewed yet) of a listed add-on: unreviewed code
            if ($picked === 3 && $allowUnlisted) {
                $addons[$name] = ['constraint' => $version, 'unlisted' => true];

                continue;
            }
            if (is_int($picked)) {
                stop(1, 'error '.match ($picked) {
                    3 => "$name has no version $version in the ISPGrid add-on list. To install it anyway, without review: --allow-unlisted",
                    4 => "$name $version was pulled back from the ISPGrid add-on list: ".$registry[$name]['versions'][$version]['yanked'],
                    default => "$name $version does not support ISPGrid $series",
                });
            }
        }
        $addons[$name] = ['version' => $version, 'commit' => null, 'pinned' => $version !== null];
        $requested[$name] = true;
    }

    foreach ($addons as $name => $entry) {
        if (! preg_match(NAME_PATTERN, (string) $name) || ! is_array($entry)) {
            stop(1, "error addons.json has an entry that is not valid: $name");
        }
        if ($registry === null) {
            continue;
        }
        $old = ! isset($entry['unlisted']) && isset($entry['constraint']);
        if (! empty($entry['unlisted']) || ($old && ! isset($registry[$name]))) {
            $addons[$name] = ['constraint' => (string) ($entry['constraint'] ?? '*'), 'unlisted' => true];
            fwrite(STDERR, "warn $name {$addons[$name]['constraint']} is not in the ISPGrid add-on list: its code was not reviewed\n");

            continue;
        }
        if (! isset($registry[$name])) {
            stop(1, "error $name is no longer in the ISPGrid add-on list: remove it with sudo ispgrid addon remove $name");
        }
        $pinned = ! $old && ! empty($entry['pinned']);
        $version = $pinned ? (string) $entry['version'] : null;
        if ($pinned && isset($registry[$name]['versions'][$version]['yanked']) && ! isset($requested[$name])) {
            $commit = $registry[$name]['versions'][$version]['commit'];
            if (! in_array($series, $registry[$name]['versions'][$version]['core'], true)) {
                stop(1, "error $name has no version for ISPGrid $series yet: wait for one, or remove it with sudo ispgrid addon remove $name");
            }
            fwrite(STDERR, "warn $name $version was pulled back from the ISPGrid add-on list: ".$registry[$name]['versions'][$version]['yanked']
                .". Move to a newer version: sudo ispgrid addon update $name\n");
            $addons[$name] = ['version' => $version, 'commit' => $commit, 'pinned' => true];

            continue;
        }
        $picked = pick($registry, $name, $series, $version);
        if (is_int($picked)) {
            stop(1, $picked === 3
                ? "error $name $version is no longer in the ISPGrid add-on list: move to a listed version with sudo ispgrid addon update $name"
                : "error $name has no version for ISPGrid $series yet: wait for one, or remove it with sudo ispgrid addon remove $name");
        }
        $addons[$name] = ['version' => $picked[0], 'commit' => $picked[1], 'pinned' => $pinned];
    }

    return $addons;
}

$args = array_slice($argv, 1);
$command = array_shift($args);
switch ($command) {
    case 'validate':
        stop(registry($args[0] ?? '') === null ? 6 : 0);
        // no break
    case 'pick':
        $picked = pick(registryOrStop($args[0] ?? ''), $args[1] ?? '', $args[2] ?? '', $args[3] ?? null);
        if (is_int($picked)) {
            stop($picked);
        }
        echo $picked[0], ' ', $picked[1], "\n";
        break;
    case 'yanked':
        $registry = registryOrStop($args[0] ?? '');
        $reason = $registry[$args[1] ?? '']['versions'][$args[2] ?? '']['yanked'] ?? '';
        if ($reason !== '') {
            echo $reason, "\n";
        }
        break;
    case 'info':
        $addon = registryOrStop($args[0] ?? '')[$args[1] ?? ''] ?? stop(2);
        echo $addon['official'] ? 'yes' : 'no', '|', $addon['author'], '|', $addon['description'], "\n";
        break;
    case 'available':
        $registry = registryOrStop($args[0] ?? '');
        ksort($registry);
        foreach (array_keys($registry) as $name) {
            $picked = pick($registry, $name, $args[1] ?? '');
            if (is_array($picked)) {
                echo $name, ' ', $picked[0], "\n";
            }
        }
        break;
    case 'resolve':
        [$addonsFile, $registryFile, $series, $allow] = array_pad(array_slice($args, 0, 4), 4, '');
        if (! preg_match(SERIES_PATTERN, $series)) {
            stop(1, "error Not a core series: $series");
        }
        $addons = is_file($addonsFile) ? json_decode((string) file_get_contents($addonsFile), true) : [];
        if (! is_array($addons)) {
            stop(1, "error $addonsFile is not valid JSON");
        }
        $registry = $registryFile === '-' ? null : registry($registryFile);
        if ($registryFile !== '-' && $registry === null) {
            stop(1, 'error The ISPGrid add-on list is not valid, so nothing was changed.');
        }
        $resolved = resolve($addons, $registry, $series, $allow === '1', array_slice($args, 4));
        echo json_encode((object) $resolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        break;
    case 'reference':
        $installed = json_decode((string) @file_get_contents($args[0] ?? ''), true);
        foreach ($installed['packages'] ?? [] as $package) {
            if (($package['name'] ?? null) === ($args[1] ?? '')) {
                $reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? '';
                if (is_string($reference) && preg_match(COMMIT_PATTERN, $reference)) {
                    echo $reference, "\n";
                }
            }
        }
        break;
    default:
        stop(2, 'Usage: php addons.php validate|pick|yanked|info|available|resolve|reference ...');
}
