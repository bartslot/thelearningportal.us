<?php

declare(strict_types=1);

namespace App\Services\Corpus;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The other names a subject goes by, from Wikidata.
 *
 * The catalog is named in English, but Dutch teachers type "Tweede Wereldoorlog",
 * "Romeinse Rijk", "Willem van Oranje". Without the alt-labels a typed topic simply
 * doesn't match anything we hold, and the lesson falls back to a fuzzy web search —
 * which is exactly the wrong-article failure App\Services\Corpus\TopicGrounding exists
 * to prevent.
 *
 * Best-effort throughout: a throttled or failed batch yields no aliases for those QIDs
 * rather than an exception. Re-running fills the gaps.
 */
final class WikidataAliases
{
    /** wbgetentities accepts at most 50 ids per call. */
    private const BATCH = 50;

    /** Enough for the names people actually type; the tail is spelling noise. */
    private const MAX_PER_ENTITY = 18;

    /**
     * @param  string[]  $qids
     * @return array<string, string> qid → pipe-joined synonyms
     */
    public function forQids(array $qids, int $timeout = 45): array
    {
        $out = [];

        foreach (array_chunk(array_values(array_unique(array_filter($qids))), self::BATCH) as $chunk) {
            try {
                $entities = Http::timeout($timeout)
                    ->withHeaders(['User-Agent' => config('app.name').' (corpus catalog builder)'])
                    ->retry(2, 1500, throw: false)
                    ->get('https://www.wikidata.org/w/api.php', [
                        'action' => 'wbgetentities',
                        'ids' => implode('|', $chunk),
                        'props' => 'aliases|labels',
                        'languages' => 'en|nl',
                        'format' => 'json',
                    ])
                    ->json('entities', []);
            } catch (Throwable $e) {
                Log::info('[WikidataAliases] batch failed, skipping: '.$e->getMessage());

                continue;
            }

            foreach ($entities as $qid => $entity) {
                $joined = self::join($entity);
                if ($joined !== null) {
                    $out[$qid] = $joined;
                }
            }
        }

        return $out;
    }

    /**
     * Flatten one wbgetentities entry into pipe-joined synonyms, Dutch label ahead of the
     * Dutch alt-labels — it's the name a teacher is most likely to type.
     *
     * @param  array<string, mixed>  $entity
     */
    public static function join(array $entity): ?string
    {
        $labels = [
            ...array_column($entity['aliases']['en'] ?? [], 'value'),
            ...array_filter([$entity['labels']['nl']['value'] ?? null]),
            ...array_column($entity['aliases']['nl'] ?? [], 'value'),
        ];

        $labels = array_values(array_unique(array_filter(array_map('trim', $labels))));

        return $labels === [] ? null : implode('|', array_slice($labels, 0, self::MAX_PER_ENTITY));
    }
}
