<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Fetches notable historical *events* (wars, revolutions, epidemics, treaties, disasters…)
 * from Wikidata, ranked by Wikipedia sitelinks. The curated topics catalog is otherwise
 * entity-only (people/polities/places), so teachers searching "Black Death" or "French
 * Revolution" found nothing — this fills that gap.
 *
 * We match on a direct instance-of (P31) an explicit list of event classes (plus their
 * common concrete subclasses like "world war"). A transitive P31/P279* walk is far too
 * heavy for the public endpoint (it 502s), so the class list is enumerated instead.
 */
class WikidataEventsService
{
    // Wikimedia blocks requests without a descriptive User-Agent (HTTP 403). See their UA policy.
    private const USER_AGENT = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    /**
     * Event classes to include, by direct P31. Curated for K-12 history and verified to
     * surface the canonical topics (WWII, French Revolution, Black Death, Russian Revolution…)
     * without pulling sports/festival noise.
     */
    private const EVENT_CLASSES = [
        'Q198',      // war
        'Q103495',   // world war
        'Q8465',     // civil war
        'Q1006311',  // war of independence
        'Q350604',   // armed conflict
        'Q178561',   // battle
        'Q188055',   // siege
        'Q10931',    // revolution
        'Q12184',    // pandemic
        'Q3241045',  // epidemic / disease outbreak
        'Q3199915',  // massacre
        'Q41397',    // genocide
        'Q131569',   // treaty
        'Q625298',   // peace treaty
        'Q8065',     // natural disaster
        'Q3839081',  // disaster
        'Q13418847', // historical event
        'Q2223653',  // terrorist attack
    ];

    private function client(int $timeout = 90): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout($timeout);
    }

    /**
     * Notable events at or above the sitelinks floor, de-duplicated by QID (an event that is
     * an instance of several classes, or carries multiple dates, comes back as several rows).
     * Each row is enriched with pipe-joined English alt-labels for synonym search.
     *
     * @return list<array{qid:string,name:string,summary:?string,aliases:?string,
     *                     wikipedia_url:string,era_start:?int,era_end:?int,sitelinks:int}>
     */
    public function notableEvents(int $minSitelinks = 25, int $limit = 4000): array
    {
        $events = $this->fetchEvents($minSitelinks, $limit);

        $aliases = $this->fetchAliases(array_column($events, 'qid'));
        foreach ($events as &$e) {
            $e['aliases'] = $aliases[$e['qid']] ?? null;
        }

        return $events;
    }

    /**
     * The core SPARQL scan (name, dates, sitelinks, article) collapsed to one row per event.
     *
     * @return list<array{qid:string,name:string,summary:?string,wikipedia_url:string,
     *                     era_start:?int,era_end:?int,sitelinks:int}>
     */
    private function fetchEvents(int $minSitelinks, int $limit): array
    {
        $values = implode(' ', array_map(fn ($q) => "wd:{$q}", self::EVENT_CLASSES));

        $sparql = <<<SPARQL
        SELECT ?e ?eLabel ?eDescription ?sitelinks ?start ?end ?point ?article WHERE {
          VALUES ?cls { {$values} }
          ?e wdt:P31 ?cls ; wikibase:sitelinks ?sitelinks .
          FILTER(?sitelinks >= {$minSitelinks})
          ?article schema:about ?e ; schema:isPartOf <https://en.wikipedia.org/> .
          OPTIONAL { ?e wdt:P580 ?start. }
          OPTIONAL { ?e wdt:P582 ?end. }
          OPTIONAL { ?e wdt:P585 ?point. }
          SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
        } ORDER BY DESC(?sitelinks) LIMIT {$limit}
        SPARQL;

        $rows = $this->client()
            ->get(self::SPARQL_ENDPOINT, ['query' => $sparql, 'format' => 'json'])
            ->json('results.bindings', []);

        return $this->collapseRows($rows);
    }

    /**
     * Collapse multi-row events into one record per QID: keep the max sitelinks, the earliest
     * start year and the latest end year seen, falling back to the point-in-time for both.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return list<array{qid:string,name:string,summary:?string,wikipedia_url:string,
     *                     era_start:?int,era_end:?int,sitelinks:int}>
     */
    private function collapseRows(array $rows): array
    {
        $byQid = [];
        foreach ($rows as $r) {
            $qid = $this->qidFromUri($r['e']['value'] ?? '');
            $article = $r['article']['value'] ?? null;
            $name = $r['eLabel']['value'] ?? null;
            // Require a real Wikipedia article and a resolved label (not the bare QID).
            if (! $qid || ! $article || ! $name || $name === $qid) {
                continue;
            }

            $point = isset($r['point']) ? $this->yearFromIso($r['point']['value']) : null;
            $start = isset($r['start']) ? $this->yearFromIso($r['start']['value']) : $point;
            $end = isset($r['end']) ? $this->yearFromIso($r['end']['value']) : $point;

            if (! isset($byQid[$qid])) {
                $desc = $r['eDescription']['value'] ?? null;
                $byQid[$qid] = [
                    'qid' => $qid,
                    'name' => $name,
                    'summary' => $desc && $desc !== '' ? $desc : null,
                    'wikipedia_url' => $article,
                    'era_start' => $start,
                    'era_end' => $end,
                    'sitelinks' => (int) ($r['sitelinks']['value'] ?? 0),
                ];

                continue;
            }

            // Merge additional rows for the same event: widen the date range, keep max sitelinks.
            $cur = &$byQid[$qid];
            if ($start !== null) {
                $cur['era_start'] = $cur['era_start'] === null ? $start : min($cur['era_start'], $start);
            }
            if ($end !== null) {
                $cur['era_end'] = $cur['era_end'] === null ? $end : max($cur['era_end'], $end);
            }
            $cur['sitelinks'] = max($cur['sitelinks'], (int) ($r['sitelinks']['value'] ?? 0));
            unset($cur);
        }

        return array_values($byQid);
    }

    /**
     * Batch-fetch English alt-labels (skos:altLabel) for a set of event QIDs via wbgetentities
     * (max 50 ids/call), returning qid → pipe-joined synonyms. Used for synonym search so
     * "Black Plague" resolves to Black Death, "The Great War" to WWI, etc. Best-effort: a
     * failed batch just yields no aliases for those events.
     *
     * @param  string[]  $qids
     * @return array<string,string>
     */
    private function fetchAliases(array $qids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($qids)), 50) as $chunk) {
            try {
                $entities = $this->client(45)->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbgetentities',
                    'ids' => implode('|', $chunk),
                    'props' => 'aliases',
                    'languages' => 'en',
                    'format' => 'json',
                ])->json('entities', []);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($entities as $qid => $entity) {
                $labels = array_column($entity['aliases']['en'] ?? [], 'value');
                if ($labels !== []) {
                    $out[$qid] = implode('|', array_slice($labels, 0, 12));
                }
            }
        }

        return $out;
    }

    private function qidFromUri(string $uri): ?string
    {
        if (preg_match('/(Q\d+)$/', $uri, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** "1789-07-14T00:00:00Z" → 1789; "-0044-03-15T..." → -44. Null when unparseable. */
    private function yearFromIso(?string $iso): ?int
    {
        if (! $iso) {
            return null;
        }
        if (preg_match('/^(-?\d+)-/', $iso, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
