<?php

declare(strict_types=1);

namespace App\Services\Corpus;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin adapter over the Europeana Search API. Returns records already mapped to our
 * Dublin Core / EDM crosswalk, so the harvester and the picker never see raw EDM.
 *
 * We only ever ask for openly-licensed images (reusability=open, TYPE:IMAGE) with a
 * usable rendition, so every record can legally back a lesson scene.
 */
class EuropeanaService
{
    /**
     * Search open-licensed images. Returns [total, cursor, items[]] where each item is
     * a crosswalked array ready to upsert into public.artworks.
     *
     * @param  array{country?:string,yearFrom?:int,yearTo?:int,rows?:int,cursor?:string}  $opts
     * @return array{total:int, cursor:?string, items:array<int,array<string,mixed>>}
     */
    public function search(string $query, array $opts = []): array
    {
        $key = (string) config('services.europeana.key');
        if ($key === '') {
            throw new RuntimeException('EUROPEANA_API_KEY is not configured.');
        }

        $qf = ['TYPE:IMAGE'];
        if (! empty($opts['country'])) {
            $qf[] = 'COUNTRY:'.$opts['country'];
        }
        if (! empty($opts['yearFrom']) || ! empty($opts['yearTo'])) {
            $from = $opts['yearFrom'] ?? 1;
            $to = $opts['yearTo'] ?? date('Y');
            $qf[] = "YEAR:[{$from} TO {$to}]";
        }

        // Europeana expects REPEATED qf params (qf=TYPE:IMAGE&qf=COUNTRY:...), which
        // http_build_query can't express — build the query string by hand.
        $qs = http_build_query([
            'wskey' => $key,
            'query' => $query,
            'reusability' => 'open',
            'media' => 'true',
            'profile' => 'rich',
            'rows' => min(100, (int) ($opts['rows'] ?? 50)),
            'cursor' => $opts['cursor'] ?? '*',
        ]);
        foreach ($qf as $facet) {
            $qs .= '&qf='.rawurlencode($facet);
        }

        $response = Http::timeout((int) config('services.europeana.timeout', 30))
            ->get(rtrim((string) config('services.europeana.base_url'), '/').'/search.json?'.$qs);

        if (! $response->successful()) {
            throw new RuntimeException("Europeana search failed ({$response->status()}): ".mb_substr($response->body(), 0, 200));
        }

        $body = $response->json();
        if (! ($body['success'] ?? false)) {
            throw new RuntimeException('Europeana error: '.($body['error'] ?? 'unknown'));
        }

        $items = array_values(array_filter(array_map(
            fn (array $r) => $this->crosswalk($r),
            $body['items'] ?? [],
        )));

        return [
            'total' => (int) ($body['totalResults'] ?? 0),
            'cursor' => $body['nextCursor'] ?? null,   // null once the last page is reached
            'items' => $items,
        ];
    }

    /** Map one EDM record → our Dublin Core-aligned artworks shape. Null if unusable. */
    private function crosswalk(array $r): ?array
    {
        $id = $r['id'] ?? null;                                   // e.g. "/2021672/resource_..."
        $image = $r['edmIsShownBy'][0] ?? null;                  // full rendition
        if (! is_string($id) || ! is_string($image) || $image === '') {
            return null;
        }

        $rights = $r['rights'][0] ?? null;
        $title = $this->first($r, ['dcTitleLangAware', 'title']) ?? 'Untitled';

        return [
            'qid' => 'europeana:'.trim($id, '/'),                // synthetic, namespaced key
            'title' => mb_substr((string) $title, 0, 500),
            'title_lang' => $r['edmLanguage'][0] ?? null,
            'creator_name' => $this->first($r, ['dcCreatorLangAware', 'dcCreator', 'edmAgentLabel']),
            'inception_year' => $this->yearOf($r['year'][0] ?? null),
            'image_url' => $image,
            'thumb_url' => $r['edmPreview'][0] ?? null,
            'source' => 'europeana',
            'source_uri' => 'https://www.europeana.eu/item'.$id,
            'rights_uri' => $rights,
            // Openly licensed (reusability=open) → usable as a background; rights_uri carries
            // the exact licence for the credit line.
            'pd_likely' => true,
            'collection' => $r['dataProvider'][0] ?? null,
            'country' => $r['country'][0] ?? null,
            'kind' => 'painting',
        ];
    }

    /** First value from the first present key; handles langAware maps ({en:[...],nl:[...]}). */
    private function first(array $r, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $r[$k] ?? null;
            if (is_array($v)) {
                $v = array_values($v)[0] ?? null;
                if (is_array($v)) {
                    $v = $v[0] ?? null;
                }
            }
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /** Europeana `year` is a 4-digit string; keep only plausible values. */
    private function yearOf($year): ?int
    {
        if (is_string($year) && preg_match('/^-?\d{1,4}$/', $year)) {
            $y = (int) $year;

            return ($y >= -3000 && $y <= (int) date('Y')) ? $y : null;
        }

        return null;
    }
}
