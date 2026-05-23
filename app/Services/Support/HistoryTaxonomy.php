<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;

class HistoryTaxonomy
{
    /** @return array<int, array{value: string, label: string, locale_weight: list<string>, eras: list<string>}> */
    public static function regions(): array
    {
        return Cache::rememberForever('history_taxonomy_regions', function () {
            $path = resource_path('data/history-regions.json');
            $data = json_decode(file_get_contents($path), true);
            return $data['regions'];
        });
    }

    /**
     * Return regions sorted so locale-matching entries come first, then alphabetical.
     */
    public static function regionsFor(string $locale): array
    {
        $regions = static::regions();
        $lang    = strtolower($locale);

        usort($regions, function (array $a, array $b) use ($lang) {
            $aWeight = static::localeScore($a['locale_weight'], $lang);
            $bWeight = static::localeScore($b['locale_weight'], $lang);

            if ($aWeight !== $bWeight) {
                return $bWeight <=> $aWeight;
            }
            return strcmp($a['label'], $b['label']);
        });

        return $regions;
    }

    /** @return list<string> */
    public static function erasFor(string $regionValue): array
    {
        foreach (static::regions() as $region) {
            if ($region['value'] === $regionValue) {
                return $region['eras'];
            }
        }
        return [];
    }

    /**
     * Return the grade system for a locale, or null if none matches.
     * Tries exact match first (e.g. "en-US"), then language prefix (e.g. "en").
     *
     * @return array{label: string, options: list<array{value: string, label: string, age_hint: int}>}|null
     */
    public static function gradeSystemFor(string $locale): ?array
    {
        return Cache::rememberForever('history_taxonomy_grade_' . $locale, function () use ($locale) {
            $path    = resource_path('data/history-regions.json');
            $data    = json_decode(file_get_contents($path), true);
            $systems = $data['grade_systems'] ?? [];

            // Normalize locale aliases before matching
            $normalized = self::normalizeLocale($locale);

            // Exact match (e.g. "en-US", "nl")
            foreach ($systems as $key => $system) {
                if (strtolower($key) === $normalized) {
                    return $system;
                }
            }

            // Language-prefix match (e.g. "nl-NL" → "nl")
            $prefix = explode('-', $normalized)[0];
            foreach ($systems as $key => $system) {
                if (strtolower($key) === $prefix) {
                    return $system;
                }
            }

            return null;
        });
    }

    /**
     * Normalize locale aliases to BCP-47 keys used in grade_systems JSON.
     * e.g. "us" → "en-US", "en" → "en-US", "gb" / "uk" → "en-GB"
     */
    private static function normalizeLocale(string $locale): string
    {
        $map = [
            'us'    => 'en-us',
            'en'    => 'en-us',
            'gb'    => 'en-gb',
            'uk'    => 'en-gb',
            'en_us' => 'en-us',
            'en_gb' => 'en-gb',
            'nl_nl' => 'nl',
        ];

        $lower = strtolower(str_replace('_', '-', $locale));
        return $map[$lower] ?? $lower;
    }

    private static function localeScore(array $weights, string $lang): int
    {
        foreach ($weights as $w) {
            if (strtolower($w) === $lang) {
                return 2; // exact match
            }
            // Match language prefix (e.g. "en" matches "en-US")
            if (str_starts_with($lang, strtolower(explode('-', $w)[0]))) {
                return 1;
            }
        }
        return 0;
    }
}
