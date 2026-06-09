<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WikidataPolityResolver
{
    // Wikimedia blocks requests without a descriptive User-Agent (HTTP 403). See their UA policy.
    private const USER_AGENT = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    private function client(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT]);
    }

    /**
     * @return array{wikidata_id:?string,label:?string,summary:?string,wikipedia_url:?string,
     *               inception:?int,dissolution:?int,predecessor:?string,successor:?string,
     *               sitelinks:int,flag_commons:?string}
     */
    public function resolve(string $name): array
    {
        $search = $this->client()->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbsearchentities', 'search' => $name, 'language' => 'en',
            'type' => 'item', 'format' => 'json', 'limit' => 1,
        ])->json('search.0');

        if (! $search) {
            return $this->emptyResult();
        }
        $qid = $search['id'];

        $entity = $this->client()->get("https://www.wikidata.org/wiki/Special:EntityData/{$qid}.json")
            ->json("entities.{$qid}", []);
        if ($entity === []) {
            return $this->emptyResult();
        }

        return $this->fromEntity($qid, $entity, $search['label'] ?? $name);
    }

    /**
     * Enrich directly from a known Wikidata QID (OHM features carry the QID, so no name search).
     *
     * @return array<string,mixed> same shape as resolve()
     */
    public function resolveByQid(string $qid): array
    {
        $entity = $this->client()->get("https://www.wikidata.org/wiki/Special:EntityData/{$qid}.json")
            ->json("entities.{$qid}", []);
        if ($entity === []) {
            return $this->emptyResult();
        }

        return $this->fromEntity($qid, $entity, $entity['labels']['en']['value'] ?? $qid);
    }

    /**
     * Build the enrichment array from a fetched Wikidata entity.
     *
     * @return array<string,mixed>
     */
    private function fromEntity(string $qid, array $entity, string $label): array
    {
        $claims = $entity['claims'] ?? [];

        $title = $entity['sitelinks']['enwiki']['title'] ?? null;
        $summary = null;
        $wikipediaUrl = null;
        if ($title) {
            $sum = $this->client()->get('https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode($title))->json();
            $summary = $sum['extract'] ?? null;
            $wikipediaUrl = $sum['content_urls']['desktop']['page'] ?? null;
        }

        $predQid = $this->itemQid($claims, 'P155');
        $succQid = $this->itemQid($claims, 'P156');
        $labels = $this->labelsFor(array_filter([$predQid, $succQid]));

        return [
            'wikidata_id' => $qid,
            'label' => $label,
            'summary' => $summary,
            'wikipedia_url' => $wikipediaUrl,
            'inception' => $this->year($claims, 'P571'),
            'dissolution' => $this->year($claims, 'P576'),
            'predecessor' => $predQid ? ($labels[$predQid] ?? $predQid) : null,
            'successor' => $succQid ? ($labels[$succQid] ?? $succQid) : null,
            'sitelinks' => count($entity['sitelinks'] ?? []),
            'flag_commons' => $claims['P41'][0]['mainsnak']['datavalue']['value'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyResult(): array
    {
        return [
            'wikidata_id' => null, 'label' => null, 'summary' => null, 'wikipedia_url' => null,
            'inception' => null, 'dissolution' => null, 'predecessor' => null, 'successor' => null,
            'sitelinks' => 0, 'flag_commons' => null,
        ];
    }

    /**
     * Resolve Wikidata item QIDs to their English labels in one batched call.
     *
     * @param  string[]  $qids
     * @return array<string,string> qid => label (missing labels omitted)
     */
    public function labelsFor(array $qids): array
    {
        $qids = array_values(array_unique(array_filter($qids)));
        if ($qids === []) {
            return [];
        }

        $entities = $this->client()->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetentities', 'ids' => implode('|', $qids),
            'props' => 'labels', 'languages' => 'en', 'format' => 'json',
        ])->json('entities', []);

        $out = [];
        foreach ($qids as $qid) {
            if (isset($entities[$qid]['labels']['en']['value'])) {
                $out[$qid] = $entities[$qid]['labels']['en']['value'];
            }
        }

        return $out;
    }

    private function year(array $claims, string $prop): ?int
    {
        $time = $claims[$prop][0]['mainsnak']['datavalue']['value']['time'] ?? null;
        if (! $time) {
            return null;
        }

        return (int) preg_replace('/^([+-]?\d+)-.*/', '$1', $time);
    }

    private function itemQid(array $claims, string $prop): ?string
    {
        return $claims[$prop][0]['mainsnak']['datavalue']['value']['id'] ?? null;
    }
}
