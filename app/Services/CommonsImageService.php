<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Live image lookup on Wikimedia Commons — complements the pre-harvested corpus
 * paintings (public.artworks) with on-demand results for any lesson topic.
 *
 * Commons structured data lets us search files by what they DEPICT
 * (haswbstatement:P180=QID), which finds far more than Wikidata-only harvesting:
 * most Commons files have no Wikidata item of their own.
 *
 * Only freely reusable licenses pass the filter (PD / CC0 / CC BY / CC BY-SA);
 * NC/ND variants are rejected. Results are cached for a day.
 */
class CommonsImageService
{
    private const API = 'https://commons.wikimedia.org/w/api.php';

    private const CACHE_TTL = 60 * 60 * 24;

    private const TIMEOUT = 8;

    /** Files depicting a Wikidata entity (P180 structured-data statement). */
    public function searchDepicting(string $qid, int $limit = 12): array
    {
        if (! preg_match('/^Q\d+$/', $qid)) {
            return [];
        }

        // The bare depicts-statement search also returns maps, flags and charts that
        // "depict" the entity — the painting term keeps results art-like.
        return Cache::remember("commons.depicts.v2.{$qid}.{$limit}", self::CACHE_TTL,
            fn () => $this->search("filetype:bitmap haswbstatement:P180={$qid} painting", $limit));
    }

    /** Free-text file search (e.g. a scene location like "Constantinople painting"). */
    public function searchText(string $term, int $limit = 12): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $key = 'commons.text.v2.'.md5($term).".{$limit}";

        return Cache::remember($key, self::CACHE_TTL,
            fn () => $this->search('filetype:bitmap '.$term, $limit));
    }

    /**
     * Full metadata for one file (used when a teacher applies it as background).
     *
     * @return array{file_title:string,title:string,artist:?string,license:string,image_url:string,file_page:string}|null
     */
    public function fileMeta(string $fileTitle): ?array
    {
        $rows = $this->query([
            'titles' => 'File:'.preg_replace('/^File:/i', '', $fileTitle),
        ]);

        return $rows[0] ?? null;
    }

    /** @return list<array{file_title:string,title:string,artist:?string,license:string,image_url:string,thumb_url:string,file_page:string}> */
    private function search(string $gsrsearch, int $limit): array
    {
        return $this->query([
            'generator' => 'search',
            'gsrsearch' => $gsrsearch,
            'gsrnamespace' => 6,           // File:
            'gsrlimit' => min($limit * 2, 40), // over-fetch: some hits fail the license filter
        ], $limit);
    }

    private function query(array $params, ?int $limit = null): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'LearningPortal/1.0 (thelearningportal.us)'])
                ->timeout(self::TIMEOUT)
                ->get(self::API, $params + [
                    'action' => 'query',
                    'format' => 'json',
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|extmetadata|size',
                    'iiurlwidth' => 800,
                ]);
            if (! $response->successful()) {
                return [];
            }
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $out = [];
        foreach ($response->json('query.pages', []) as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (! $info) {
                continue;
            }
            $meta = $info['extmetadata'] ?? [];
            $license = trim((string) ($meta['LicenseShortName']['value'] ?? ''));
            if (! $this->isFreeLicense($license)) {
                continue;
            }
            // Skinny images (icons, borders) and tiny scans make poor backgrounds.
            if (($info['width'] ?? 0) < 640) {
                continue;
            }
            $fileTitle = preg_replace('/^File:/', '', (string) $page['title']);
            $out[] = [
                'file_title' => $fileTitle,
                'title' => $this->cleanText($meta['ObjectName']['value'] ?? pathinfo($fileTitle, PATHINFO_FILENAME)),
                'artist' => $this->cleanText($meta['Artist']['value'] ?? '') ?: null,
                'license' => $license,
                // Special:FilePath renders any width on demand — same scheme as corpus artworks.
                'image_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($fileTitle),
                'thumb_url' => $info['thumburl'] ?? $info['url'],
                'file_page' => $info['descriptionurl'] ?? ('https://commons.wikimedia.org/wiki/File:'.rawurlencode($fileTitle)),
            ];
            if ($limit !== null && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function isFreeLicense(string $license): bool
    {
        if ($license === '') {
            return false;
        }
        if (preg_match('/\b(NC|ND)\b/i', $license)) {
            return false;
        }

        return (bool) preg_match('/public domain|^PD|CC0|CC[ -]BY/i', $license);
    }

    private function cleanText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
