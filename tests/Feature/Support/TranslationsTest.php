<?php

use Symfony\Component\Finder\Finder;

/**
 * Every string the panel passes to __(), trans_choice() or #[Title()] with a literal key.
 *
 * @return list<string>
 */
function panelStrings(): array
{
    $files = Finder::create()->files()->in([base_path('app'), resource_path('views')])->name(['*.php']);
    $call = '/(?:\b__|\btrans_choice|#\[Title)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';
    $strings = [];

    foreach ($files as $file) {
        preg_match_all($call, $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $strings[] = ($match[1] ?? '') !== '' ? stripcslashes(str_replace("\\'", "'", $match[1])) : stripcslashes($match[2]);
        }
    }

    return array_values(array_unique(array_filter($strings, fn (string $string): bool => $string !== '')));
}

/**
 * @return array<string, array<string, string>> the translations of every shipped language except English, by code
 */
function shippedTranslations(): array
{
    $languages = [];

    foreach (array_diff(array_keys(config('app.locales')), ['en']) as $locale) {
        $languages[$locale] = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    return $languages;
}

it('ships every panel string in each language', function () {
    expect(shippedTranslations())->not->toBe([]);

    foreach (shippedTranslations() as $locale => $translations) {
        $missing = array_values(array_filter(panelStrings(), fn (string $string): bool => ! array_key_exists($string, $translations)));

        expect($missing)->toBe([], "Missing in lang/{$locale}.json");
    }
});

it('keeps the placeholders of every string in its translation', function () {
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        $names = array_unique($found[0]);
        sort($names);

        return $names;
    };

    foreach (shippedTranslations() as $locale => $translations) {
        $broken = array_keys(array_filter($translations, fn (string $translation, string $key): bool => $placeholders($translation) !== $placeholders($key), ARRAY_FILTER_USE_BOTH));

        expect($broken)->toBe([], "Placeholders changed in lang/{$locale}.json");
    }
});

it('finds the panel strings it checks', function () {
    expect(panelStrings())
        ->toContain('Dashboard', 'App settings', ':count plan|:count plans')
        ->and(count(panelStrings()))->toBeGreaterThan(500);
});
