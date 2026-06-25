<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Read-only lookup over the curated polity → capital map (database/data/polity_capitals.php).
 *
 * Resolves a polity's Wikidata QID to its best-known capital city for map features. The data
 * file is required once and cached for the process.
 */
final class PolityCapitals
{
    /**
     * Cached copy of the data file, keyed by polity QID.
     *
     * @var array<string, array{city: string, qid: string}>|null
     */
    private static ?array $map = null;

    /**
     * Capital for a polity QID, or null when the polity is not in the curated map.
     *
     * @return array{city: string, qid: string}|null
     */
    public static function for(string $qid): ?array
    {
        return self::all()[$qid] ?? null;
    }

    /**
     * The full polity → capital map, loaded once.
     *
     * @return array<string, array{city: string, qid: string}>
     */
    public static function all(): array
    {
        if (self::$map === null) {
            $path = database_path('data/polity_capitals.php');
            $data = is_file($path) ? require $path : [];
            self::$map = is_array($data) ? $data : [];
        }

        return self::$map;
    }

    /** Drop the in-process cache (useful in tests after swapping the data file). */
    public static function flush(): void
    {
        self::$map = null;
    }
}
