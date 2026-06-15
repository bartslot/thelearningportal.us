<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Fetches notable figures (rulers + people) and region coordinates for a Cliopatria polity,
 * keyed by its Wikidata QID. Mirrors how oldmapsonline.org TimeMap drives its Rulers/People
 * layers: rulers come from the polity's head-of-state (P35) / head-of-government (P6) statements
 * with reign qualifiers (P580/P582); people come from a reverse citizenship query (P27),
 * ranked by Wikipedia sitelinks.
 */
class WikidataFiguresService
{
    // Wikimedia blocks requests without a descriptive User-Agent (HTTP 403). See their UA policy.
    private const USER_AGENT = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    private function client(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(60);
    }

    /**
     * Fetch the polity entity once and derive region + rulers from its claims.
     *
     * @return array{
     *   region: array{lat: ?float, lng: ?float, label: ?string},
     *   rulers: list<array{qid:string,name:string,kind:string,era_start:?int,era_end:?int,
     *                       wikipedia_url:?string,summary:?string,sitelinks:int,
     *                       region_lat:?float,region_lng:?float,region_label:?string}>
     * }
     */
    public function polityFigures(string $polityQid): array
    {
        $entity = $this->client()
            ->get("https://www.wikidata.org/wiki/Special:EntityData/{$polityQid}.json")
            ->json("entities.{$polityQid}", []);

        if ($entity === []) {
            return ['region' => ['lat' => null, 'lng' => null, 'label' => null], 'rulers' => []];
        }

        $claims = $entity['claims'] ?? [];

        return [
            'region' => $this->regionFromClaims($claims),
            'rulers' => $this->rulersFromClaims($claims),
        ];
    }

    /**
     * Notable people of a polity: reverse citizenship (P27) query, ranked by sitelinks.
     *
     * @return list<array{qid:string,name:string,kind:string,era_start:?int,era_end:?int,
     *                     wikipedia_url:?string,summary:?string,sitelinks:int,
     *                     region_lat:?float,region_lng:?float,region_label:?string}>
     */
    public function peopleFor(string $polityQid, int $limit = 8): array
    {
        $sparql = <<<SPARQL
        SELECT ?person ?personLabel ?birth ?death ?article ?sitelinks WHERE {
          ?person wdt:P27 wd:{$polityQid} ; wikibase:sitelinks ?sitelinks .
          OPTIONAL { ?person wdt:P569 ?birth. }
          OPTIONAL { ?person wdt:P570 ?death. }
          OPTIONAL { ?article schema:about ?person ; schema:isPartOf <https://en.wikipedia.org/> . }
          SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
        } ORDER BY DESC(?sitelinks) LIMIT {$limit}
        SPARQL;

        $rows = $this->client()
            ->get(self::SPARQL_ENDPOINT, ['query' => $sparql, 'format' => 'json'])
            ->json('results.bindings', []);

        $out = [];
        foreach ($rows as $r) {
            $qid = $this->qidFromUri($r['person']['value'] ?? '');
            if (! $qid) {
                continue;
            }
            $out[] = [
                'qid' => $qid,
                'name' => $r['personLabel']['value'] ?? $qid,
                'kind' => 'person',
                'era_start' => isset($r['birth']) ? $this->yearFromIso($r['birth']['value']) : null,
                'era_end' => isset($r['death']) ? $this->yearFromIso($r['death']['value']) : null,
                'wikipedia_url' => $r['article']['value'] ?? null,
                'summary' => null,
                'sitelinks' => (int) ($r['sitelinks']['value'] ?? 0),
                'region_lat' => null,
                'region_lng' => null,
                'region_label' => null,
            ];
        }

        return $out;
    }

    /**
     * Hydrate summaries + sitelinks for a batch of figure QIDs (one wbgetentities call).
     * Used after collecting ruler QIDs from claims (people already carry sitelinks from SPARQL).
     *
     * @param  string[]  $qids
     * @return array<string,array{name:?string,wikipedia_url:?string,summary:?string,
     *                             sitelinks:int,birth:?int,death:?int}>
     */
    public function hydrate(array $qids): array
    {
        $qids = array_values(array_unique(array_filter($qids)));
        if ($qids === []) {
            return [];
        }

        $out = [];
        // wbgetentities caps at 50 ids per call.
        foreach (array_chunk($qids, 50) as $chunk) {
            $entities = $this->client()->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbgetentities', 'ids' => implode('|', $chunk),
                'props' => 'labels|claims|sitelinks', 'languages' => 'en', 'format' => 'json',
            ])->json('entities', []);

            foreach ($chunk as $qid) {
                $e = $entities[$qid] ?? null;
                if (! $e) {
                    continue;
                }
                $claims = $e['claims'] ?? [];
                $title = $e['sitelinks']['enwiki']['title'] ?? null;
                $out[$qid] = [
                    'name' => $e['labels']['en']['value'] ?? null,
                    'wikipedia_url' => $title
                        ? 'https://en.wikipedia.org/wiki/'.str_replace(' ', '_', $title)
                        : null,
                    'summary' => null,
                    'sitelinks' => count($e['sitelinks'] ?? []),
                    'birth' => $this->yearFromClaim($claims, 'P569'),
                    'death' => $this->yearFromClaim($claims, 'P570'),
                ];
            }
        }

        return $out;
    }

    // ── Claim parsing ─────────────────────────────────────────────────────────

    /** @return array{lat: ?float, lng: ?float, label: ?string} */
    private function regionFromClaims(array $claims): array
    {
        // 1. Coordinate location (P625) directly on the polity.
        $coord = $claims['P625'][0]['mainsnak']['datavalue']['value'] ?? null;
        $lat = $coord['latitude'] ?? null;
        $lng = $coord['longitude'] ?? null;

        // 2. Country (P17) → modern-day label (resolved by caller via labelsFor if desired).
        $countryQid = $claims['P17'][0]['mainsnak']['datavalue']['value']['id'] ?? null;

        return [
            'lat' => $lat !== null ? (float) $lat : null,
            'lng' => $lng !== null ? (float) $lng : null,
            'label' => $countryQid,  // caller resolves QID → label
        ];
    }

    /**
     * Extract ruler statements (P35 head of state + P6 head of government) with reign qualifiers.
     *
     * @return list<array<string,mixed>>
     */
    private function rulersFromClaims(array $claims): array
    {
        $out = [];
        foreach (['P35', 'P6'] as $prop) {
            foreach ($claims[$prop] ?? [] as $stmt) {
                $qid = $stmt['mainsnak']['datavalue']['value']['id'] ?? null;
                if (! $qid) {
                    continue;
                }
                $quals = $stmt['qualifiers'] ?? [];
                $out[] = [
                    'qid' => $qid,
                    'name' => $qid,            // hydrated later
                    'kind' => 'ruler',
                    'era_start' => $this->yearFromQualifier($quals, 'P580'),
                    'era_end' => $this->yearFromQualifier($quals, 'P582'),
                    'wikipedia_url' => null,
                    'summary' => null,
                    'sitelinks' => 0,
                    'region_lat' => null,
                    'region_lng' => null,
                    'region_label' => null,
                ];
            }
        }

        return $out;
    }

    private function yearFromClaim(array $claims, string $prop): ?int
    {
        $time = $claims[$prop][0]['mainsnak']['datavalue']['value']['time'] ?? null;

        return $time ? $this->yearFromWikidataTime($time) : null;
    }

    private function yearFromQualifier(array $quals, string $prop): ?int
    {
        $time = $quals[$prop][0]['datavalue']['value']['time'] ?? null;

        return $time ? $this->yearFromWikidataTime($time) : null;
    }

    /** Wikidata time literal "+0044-00-00T00:00:00Z" / "-0509-..." → signed year int. */
    private function yearFromWikidataTime(string $time): ?int
    {
        if (! preg_match('/^([+-])(\d+)-/', $time, $m)) {
            return null;
        }

        return (int) ($m[1] === '-' ? -1 : 1) * (int) $m[2];
    }

    /** SPARQL returns dateTime "1769-08-15T00:00:00Z" or "-0044-..." → signed year int. */
    private function yearFromIso(string $iso): ?int
    {
        if (! preg_match('/^(-?)(\d{1,})-/', $iso, $m)) {
            return null;
        }

        return (int) ($m[1] === '-' ? -1 : 1) * (int) $m[2];
    }

    private function qidFromUri(string $uri): ?string
    {
        return preg_match('#/(Q\d+)$#', $uri, $m) ? $m[1] : null;
    }
}
