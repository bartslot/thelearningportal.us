<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Search Sketchfab for 3D models, so a teacher picks one in OUR modal instead of being sent to
 * another site to copy a link back into a browser prompt.
 *
 * The v3 search API is public — no key, no account — and returns everything a picker tile needs:
 * a thumbnail, the model's name, who made it and under which licence. Embedding is what the
 * `embed` URL is for, and the author's name travels with the tile so attribution is never lost.
 *
 * Failures are never cached. A rate-limited moment must not turn into "no models found" for the
 * rest of the day, which is exactly the trap the Commons search fell into.
 */
class SketchfabService
{
    private const API = 'https://api.sketchfab.com/v3/search';

    private const CACHE_TTL = 60 * 60 * 6;

    private const TIMEOUT = 10;

    /** Why the last search came back empty: null = genuinely nothing, else 'throttled'|'unavailable'. */
    public ?string $lastError = null;

    /**
     * Models matching a free-text term, newest-relevance first.
     *
     * @return list<array{uid:string,name:string,author:string,license:string,thumb:string,viewer:string}>
     */
    public function search(string $term, int $limit = 24): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $key = 'sketchfab.search.v1.'.md5($term).".{$limit}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $this->lastError = null;

            return $cached;
        }

        $rows = $this->query($term, $limit);
        if ($rows === null) {
            return [];   // lastError is set; nothing is written
        }
        Cache::put($key, $rows, self::CACHE_TTL);

        return $rows;
    }

    /** @return list<array<string,string>>|null null = the request failed (see $lastError) */
    private function query(string $term, int $limit): ?array
    {
        $this->lastError = null;
        try {
            $response = Http::withHeaders(['User-Agent' => 'LearningPortal/1.0 (thelearningportal.us)'])
                ->timeout(self::TIMEOUT)
                ->get(self::API, [
                    'type' => 'models',
                    'q' => $term,
                    'count' => min(24, max(1, $limit)),
                    // A model that cannot be embedded is no use to a lesson.
                    'restricted' => 0,
                ]);

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
        foreach ($response->json('results', []) as $m) {
            $uid = (string) ($m['uid'] ?? '');
            // The renderer keys everything off the 32-hex model id; anything else is not embeddable.
            if (! preg_match('/^[0-9a-f]{32}$/i', $uid)) {
                continue;
            }
            $out[] = [
                'uid' => strtolower($uid),
                'name' => trim((string) ($m['name'] ?? 'Untitled')),
                'author' => trim((string) ($m['user']['displayName'] ?? $m['user']['username'] ?? '')),
                'license' => trim((string) ($m['license']['label'] ?? '')),
                'thumb' => $this->thumb($m),
                'viewer' => (string) ($m['viewerUrl'] ?? "https://sketchfab.com/models/{$uid}"),
            ];
        }

        return $out;
    }

    /**
     * The widest thumbnail under ~1000px. Sketchfab returns several sizes; the smallest is too
     * soft for a picker tile and the largest is a needless download for a grid.
     *
     * @param  array<string,mixed>  $model
     */
    private function thumb(array $model): string
    {
        $images = $model['thumbnails']['images'] ?? [];
        usort($images, fn ($a, $b) => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));
        foreach ($images as $img) {
            if ((int) ($img['width'] ?? 0) <= 1000) {
                return (string) ($img['url'] ?? '');
            }
        }

        return (string) ($images[0]['url'] ?? '');
    }
}
