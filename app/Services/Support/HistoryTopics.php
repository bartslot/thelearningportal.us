<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;

class HistoryTopics
{
    private const MAX_RESULTS = 8;

    /** @return list<array{topic: string, region: string, era: string}> */
    public static function all(): array
    {
        return Cache::rememberForever('history_topics_all', function () {
            $path = resource_path('data/history-topics.json');
            return json_decode(file_get_contents($path), true);
        });
    }

    /**
     * Search topics by query string. Returns up to MAX_RESULTS matches.
     * Prioritises prefix/start-of-word matches over substring matches.
     *
     * @return list<array{topic: string, region: string, era: string}>
     */
    public static function search(string $query): array
    {
        $query = strtolower(trim($query));

        if (strlen($query) < 2) {
            return [];
        }

        $all     = static::all();
        $prefix  = [];
        $contain = [];

        foreach ($all as $entry) {
            $label = strtolower($entry['topic']);

            if (str_starts_with($label, $query)) {
                $prefix[] = $entry;
            } elseif (str_contains($label, $query)) {
                $contain[] = $entry;
            }

            if (count($prefix) + count($contain) >= self::MAX_RESULTS * 3) {
                break; // early exit — enough candidates
            }
        }

        return array_slice(array_merge($prefix, $contain), 0, self::MAX_RESULTS);
    }
}
