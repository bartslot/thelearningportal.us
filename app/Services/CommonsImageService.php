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

    /** Width requested for picker grid cards. Full-size comes from image_url when one is chosen. */
    private const THUMB_WIDTH = 400;

    /**
     * Why the last search came back empty: null when it genuinely found nothing, 'throttled' when
     * Wikimedia rate-limited us, 'unavailable' for any other failure. Callers use it to tell a
     * teacher "try again in a moment" instead of the untrue "no paintings found".
     */
    public ?string $lastError = null;

    /** Files depicting a Wikidata entity (P180 structured-data statement). */
    public function searchDepicting(string $qid, int $limit = 12): array
    {
        if (! preg_match('/^Q\d+$/', $qid)) {
            return [];
        }

        // The bare depicts-statement search also returns maps, flags and charts that
        // "depict" the entity — the painting term keeps results art-like.
        return $this->remember("commons.depicts.v3.{$qid}.{$limit}",
            fn () => $this->search("filetype:bitmap haswbstatement:P180={$qid} painting", $limit));
    }

    /** Free-text file search (e.g. a scene location like "Constantinople painting"). */
    public function searchText(string $term, int $limit = 12): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        return $this->remember('commons.text.v3.'.md5($term).".{$limit}",
            fn () => $this->search('filetype:bitmap '.$term, $limit));
    }

    /**
     * Cache a search ONLY when it actually succeeded.
     *
     * Cache::remember cannot tell "Wikimedia found nothing" from "Wikimedia said 429", and this
     * cache lasts a day: one throttled moment used to pin an empty grid on a search term for 24
     * hours, which is what "No paintings found" was really reporting. A failure is now not
     * written at all, so the next attempt asks again.
     *
     * @param  callable(): ?list<array<string,mixed>>  $fetch
     * @return list<array<string,mixed>>
     */
    private function remember(string $key, callable $fetch): array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $this->lastError = null;

            return $cached;
        }

        $rows = $fetch();
        if ($rows === null) {
            return [];   // transport failure — lastError is set, nothing is cached
        }

        Cache::put($key, $rows, self::CACHE_TTL);

        return $rows;
    }

    /**
     * Full metadata for one file (used when a teacher applies it as background).
     *
     * @return array{file_title:string,title:string,artist:?string,license:string,width:int,height:int,image_url:string,file_page:string}|null
     */
    public function fileMeta(string $fileTitle): ?array
    {
        $rows = $this->query([
            'titles' => 'File:'.preg_replace('/^File:/i', '', $fileTitle),
        ]);

        return $rows[0] ?? null;
    }

    /** @return list<array{file_title:string,title:string,artist:?string,license:string,width:int,height:int,image_url:string,thumb_url:string,file_page:string}>|null null = the request failed */
    private function search(string $gsrsearch, int $limit): ?array
    {
        return $this->query([
            'generator' => 'search',
            'gsrsearch' => $gsrsearch,
            'gsrnamespace' => 6,           // File:
            'gsrlimit' => min($limit * 2, 40), // over-fetch: some hits fail the license filter
        ], $limit);
    }

    /** @return list<array<string,mixed>>|null null = the request failed (see $lastError) */
    private function query(array $params, ?int $limit = null): ?array
    {
        $this->lastError = null;
        try {
            $response = Http::withHeaders(['User-Agent' => 'LearningPortal/1.0 (thelearningportal.us)'])
                ->timeout(self::TIMEOUT)
                ->get(self::API, $params + [
                    'action' => 'query',
                    'format' => 'json',
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|extmetadata|size',
                    // Grid-card width, not full size. Commons renders whatever width we ask for, and
                    // asking for 800 to fill a ~230px card cost ~4× the bytes on every result; the
                    // chosen painting is downloaded at full size separately. See thumb_url below.
                    'iiurlwidth' => self::THUMB_WIDTH,
                ]);
            // Wikimedia throttles bursts from one address. That is a "come back in a moment",
            // never a "this painting does not exist" — say which, and do not cache it.
            if ($response->status() === 429) {
                $this->lastError = 'throttled';

                return null;
            }
            if (! $response->successful()) {
                $this->lastError = 'unavailable';

                return null;
            }
        } catch (\Throwable $e) {
            report($e);
            $this->lastError = 'unavailable';

            return null;
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
                // Pixel dimensions travel with the hit so callers can tell a portrait canvas from a
                // landscape one without re-fetching (PortraitFocus uses it to anchor the crop).
                'width' => (int) ($info['width'] ?? 0),
                'height' => (int) ($info['height'] ?? 0),
                // Special:FilePath renders any width on demand — same scheme as corpus artworks.
                'image_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($fileTitle),
                // Never fall back to $info['url'] — that is the untouched original, and a grid of
                // them pulls tens of megabytes to fill cards a few hundred pixels wide. When
                // Commons declines to render a thumb, ask Special:FilePath for the width instead.
                'thumb_url' => $info['thumburl']
                    ?? 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($fileTitle).'?width='.self::THUMB_WIDTH,
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
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        // Commons renders its {{Title}} / {{Creator}} templates with the Wikidata QuickStatements
        // markers inline, so ObjectName arrives looking like:
        //   My Painting title QS:P1476,en:"My Painting"label QS:Len,"My Painting"
        // The human-readable part always comes FIRST, so cut from the first marker onwards —
        // otherwise that whole statement ends up in the credit line printed under the image.
        $text = preg_replace('/\s*\b[a-z]+\s+QS:.*$/is', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
