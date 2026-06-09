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
        $empty = [
            'wikidata_id' => null, 'label' => null, 'summary' => null, 'wikipedia_url' => null,
            'inception' => null, 'dissolution' => null, 'predecessor' => null, 'successor' => null,
            'sitelinks' => 0, 'flag_commons' => null,
        ];

        $search = $this->client()->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbsearchentities', 'search' => $name, 'language' => 'en',
            'type' => 'item', 'format' => 'json', 'limit' => 1,
        ])->json('search.0');

        if (! $search) {
            return $empty;
        }
        $qid = $search['id'];

        $entity = $this->client()->get("https://www.wikidata.org/wiki/Special:EntityData/{$qid}.json")
            ->json("entities.{$qid}", []);
        $claims = $entity['claims'] ?? [];

        $title = $entity['sitelinks']['enwiki']['title'] ?? null;
        $summary = null;
        $wikipediaUrl = null;
        if ($title) {
            $sum = $this->client()->get('https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode($title))->json();
            $summary = $sum['extract'] ?? null;
            $wikipediaUrl = $sum['content_urls']['desktop']['page'] ?? null;
        }

        return [
            'wikidata_id' => $qid,
            'label' => $search['label'] ?? $name,
            'summary' => $summary,
            'wikipedia_url' => $wikipediaUrl,
            'inception' => $this->year($claims, 'P571'),
            'dissolution' => $this->year($claims, 'P576'),
            'predecessor' => $this->itemLabel($claims, 'P155'),
            'successor' => $this->itemLabel($claims, 'P156'),
            'sitelinks' => count($entity['sitelinks'] ?? []),
            'flag_commons' => $claims['P41'][0]['mainsnak']['datavalue']['value'] ?? null,
        ];
    }

    private function year(array $claims, string $prop): ?int
    {
        $time = $claims[$prop][0]['mainsnak']['datavalue']['value']['time'] ?? null;
        if (! $time) {
            return null;
        }

        return (int) preg_replace('/^([+-]?\d+)-.*/', '$1', $time);
    }

    private function itemLabel(array $claims, string $prop): ?string
    {
        return $claims[$prop][0]['mainsnak']['datavalue']['value']['id'] ?? null;
    }
}
