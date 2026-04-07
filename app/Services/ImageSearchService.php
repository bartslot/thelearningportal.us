<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches historically relevant images for a lesson topic.
 *
 * Provider chain:
 *   1. Europeana  — curated European cultural heritage (open licence)
 *   2. Wikimedia  — images embedded in the Wikipedia article for the topic
 *
 * Returns an array of image descriptors:
 *   [
 *     'url'         => string,   // Direct image URL (usable in <img src>)
 *     'thumb'       => string,   // Smaller thumbnail URL (may equal url)
 *     'title'       => string,   // Human-readable title
 *     'attribution' => string,   // "Creator — Provider" credit line
 *     'source'      => string,   // 'europeana' | 'wikimedia'
 *     'link'        => string|null,  // Link to the record page (for attribution)
 *   ]
 */
class ImageSearchService
{
    private const EUROPEANA_SEARCH = 'https://api.europeana.eu/record/v2/search.json';
    private const WIKIMEDIA_MEDIA  = 'https://en.wikipedia.org/api/rest_v1/page/media-list/';
    private const WIKIMEDIA_SEARCH = 'https://en.wikipedia.org/w/api.php';

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Fetch up to $limit images for the given topic.
     *
     * @return array<int, array{url:string, thumb:string, title:string, attribution:string, source:string, link:string|null}>
     */
    public function fetchImages(string $topic, int $limit = 12): array
    {
        $images = [];

        // 1. Europeana (primary — rich cultural heritage archive)
        try {
            $images = $this->searchEuropeana($topic, $limit);
            Log::info("ImageSearch: Europeana returned " . count($images) . " images for '{$topic}'");
        } catch (\Throwable $e) {
            Log::warning("ImageSearch: Europeana error for '{$topic}' — " . $e->getMessage());
        }

        // 2. Wikimedia Commons fallback — fill remaining slots
        if (count($images) < 3) {
            try {
                $needed    = $limit - count($images);
                $wikimedia = $this->searchWikimedia($topic, $needed);
                $images    = array_merge($images, $wikimedia);
                Log::info("ImageSearch: Wikimedia added " . count($wikimedia) . " images for '{$topic}'");
            } catch (\Throwable $e) {
                Log::warning("ImageSearch: Wikimedia error for '{$topic}' — " . $e->getMessage());
            }
        }

        // Filter out any svg / non-raster formats that browsers can't display inline
        $images = array_values(array_filter(
            $images,
            fn ($img) => ! empty($img['url']) && ! str_ends_with(strtolower($img['url']), '.svg')
        ));

        return array_slice($images, 0, $limit);
    }

    // ── Europeana ────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{url:string, thumb:string, title:string, attribution:string, source:string, link:string|null}>
     */
    private function searchEuropeana(string $topic, int $limit): array
    {
        $key = config('services.europeana.key');

        if (! $key) {
            Log::debug('ImageSearch: EUROPEANA_API_KEY not set — skipping');
            return [];
        }

        $response = Http::timeout(12)->get(self::EUROPEANA_SEARCH, [
            'wskey'        => $key,
            'query'        => $topic,
            'media'        => 'true',
            'reusability'  => 'open',
            'type'         => 'IMAGE',
            'rows'         => $limit,
            'profile'      => 'minimal',
            'sort'         => 'score desc',
        ]);

        if (! $response->ok()) {
            Log::warning("ImageSearch: Europeana HTTP {$response->status()} for '{$topic}'");
            return [];
        }

        /** @var array $items */
        $items = $response->json('items') ?? [];

        return collect($items)
            ->map(function (array $item) use ($topic): ?array {
                $previews = $item['edmPreview'] ?? [];
                $url = is_array($previews) ? ($previews[0] ?? null) : $previews;

                if (! $url) {
                    return null;
                }

                $title    = is_array($item['title'] ?? null) ? ($item['title'][0] ?? $topic) : ($item['title'] ?? $topic);
                $creator  = is_array($item['dcCreator'] ?? null) ? ($item['dcCreator'][0] ?? '') : ($item['dcCreator'] ?? '');
                $provider = is_array($item['dataProvider'] ?? null) ? ($item['dataProvider'][0] ?? 'Europeana') : ($item['dataProvider'] ?? 'Europeana');
                $link     = is_array($item['edmIsShownAt'] ?? null) ? ($item['edmIsShownAt'][0] ?? null) : ($item['edmIsShownAt'] ?? null);

                $attribution = trim(($creator ? $creator . ' — ' : '') . $provider);

                return [
                    'url'         => (string) $url,
                    'thumb'       => (string) $url,
                    'title'       => (string) $title,
                    'attribution' => $attribution ?: 'Europeana',
                    'source'      => 'europeana',
                    'link'        => $link ? (string) $link : null,
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    // ── Wikimedia Commons ────────────────────────────────────────────────────

    /**
     * Fetches images used in the Wikipedia article for the topic.
     * Falls back to a Commons search if the article media-list returns nothing.
     *
     * @return array<int, array{url:string, thumb:string, title:string, attribution:string, source:string, link:string|null}>
     */
    private function searchWikimedia(string $topic, int $limit): array
    {
        // Try the Wikipedia article media-list first (most relevant images)
        $images = $this->fetchWikipediaMediaList($topic, $limit);

        // If that yields nothing, try a direct Commons file search
        if (count($images) < 2) {
            $images = array_merge($images, $this->searchCommonsFiles($topic, $limit - count($images)));
        }

        return $images;
    }

    /**
     * Uses the Wikipedia REST API to get the images embedded in the article.
     *
     * @return array<int, array{url:string, thumb:string, title:string, attribution:string, source:string, link:string|null}>
     */
    private function fetchWikipediaMediaList(string $topic, int $limit): array
    {
        $slug = str_replace(' ', '_', $topic);
        $response = Http::timeout(10)
            ->withHeaders(['Api-User-Agent' => 'thelearningportal.us/1.0 (contact@thelearningportal.us)'])
            ->get(self::WIKIMEDIA_MEDIA . rawurlencode($slug));

        if (! $response->ok()) {
            return [];
        }

        /** @var array $items */
        $items = $response->json('items') ?? [];

        return collect($items)
            ->filter(fn (array $item): bool =>
                ($item['type'] ?? '') === 'image'
                && ! empty($item['srcset'])
                && ! $this->isIconOrDiagram($item['title'] ?? '')
            )
            ->take($limit)
            ->map(function (array $item): ?array {
                $srcset = $item['srcset'] ?? [];
                $url    = $this->bestSrcsetUrl($srcset);
                $thumb  = $this->smallestSrcsetUrl($srcset) ?? $url;

                if (! $url) {
                    return null;
                }

                // Make protocol-relative URLs absolute
                if (str_starts_with($url, '//')) {
                    $url   = 'https:' . $url;
                    $thumb = 'https:' . ($thumb ?? $url);
                }

                $title       = $item['title'] ?? $item['caption']['text'] ?? '';
                $title       = preg_replace('/^File:/i', '', (string) $title);
                $caption     = strip_tags((string) ($item['caption']['html'] ?? $item['caption']['text'] ?? ''));
                $attribution = $caption ?: 'Wikimedia Commons';
                $filePage    = $item['file_page'] ?? null;

                return [
                    'url'         => $url,
                    'thumb'       => (string) ($thumb ?? $url),
                    'title'       => trim((string) $title),
                    'attribution' => trim($attribution),
                    'source'      => 'wikimedia',
                    'link'        => $filePage ? (string) $filePage : null,
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Full-text search of Wikimedia Commons image files.
     *
     * @return array<int, array{url:string, thumb:string, title:string, attribution:string, source:string, link:string|null}>
     */
    private function searchCommonsFiles(string $topic, int $limit): array
    {
        // Step 1: search for file names
        $searchResponse = Http::timeout(10)
            ->withHeaders(['Api-User-Agent' => 'thelearningportal.us/1.0'])
            ->get('https://commons.wikimedia.org/w/api.php', [
                'action'      => 'query',
                'list'        => 'search',
                'srnamespace' => 6, // File namespace
                'srsearch'    => $topic . ' historical',
                'srlimit'     => $limit * 2,
                'format'      => 'json',
            ]);

        if (! $searchResponse->ok()) {
            return [];
        }

        $results = $searchResponse->json('query.search') ?? [];

        if (empty($results)) {
            return [];
        }

        $titles = collect($results)
            ->pluck('title')
            ->filter(fn ($t) => ! $this->isIconOrDiagram((string) $t))
            ->take($limit)
            ->implode('|');

        // Step 2: get image URLs for those files
        $infoResponse = Http::timeout(10)
            ->withHeaders(['Api-User-Agent' => 'thelearningportal.us/1.0'])
            ->get('https://commons.wikimedia.org/w/api.php', [
                'action'   => 'query',
                'titles'   => $titles,
                'prop'     => 'imageinfo',
                'iiprop'   => 'url|extmetadata',
                'iiurlwidth' => 800,
                'format'   => 'json',
            ]);

        if (! $infoResponse->ok()) {
            return [];
        }

        $pages = $infoResponse->json('query.pages') ?? [];

        return collect($pages)
            ->map(function (array $page): ?array {
                $info  = $page['imageinfo'][0] ?? null;
                if (! $info || empty($info['url'])) {
                    return null;
                }

                $meta        = $info['extmetadata'] ?? [];
                $author      = strip_tags($meta['Artist']['value'] ?? '');
                $description = strip_tags($meta['ImageDescription']['value'] ?? '');
                $attribution = trim(($author ? $author . ' — ' : '') . 'Wikimedia Commons');

                return [
                    'url'         => $info['url'],
                    'thumb'       => $info['thumburl'] ?? $info['url'],
                    'title'       => $description ?: preg_replace('/^File:/i', '', (string) ($page['title'] ?? '')),
                    'attribution' => $attribution,
                    'source'      => 'wikimedia',
                    'link'        => $info['descriptionurl'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Return the largest image URL from a srcset array.
     */
    private function bestSrcsetUrl(array $srcset): ?string
    {
        usort($srcset, fn ($a, $b) => (float) ($b['scale'] ?? 1) <=> (float) ($a['scale'] ?? 1));
        $src = ($srcset[0]['src'] ?? null);
        return $src ? (string) $src : null;
    }

    /**
     * Return the smallest image URL from a srcset array (for thumbnails).
     */
    private function smallestSrcsetUrl(array $srcset): ?string
    {
        usort($srcset, fn ($a, $b) => (float) ($a['scale'] ?? 1) <=> (float) ($b['scale'] ?? 1));
        $src = ($srcset[0]['src'] ?? null);
        return $src ? (string) $src : null;
    }

    /**
     * Filter out icons, logos, flags, and diagrams that pollute lesson slideshows.
     */
    private function isIconOrDiagram(string $title): bool
    {
        $lower = strtolower($title);
        $blocked = ['flag_of_', 'icon_', 'logo_', 'map_of_', 'coat_of_arms', 'wikimedia-logo',
                    'commons-logo', 'symbol_', 'blank_', 'template', 'svg'];

        foreach ($blocked as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
