<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads the marketing site's WordPress as a headless CMS.
 *
 * thelearningportal.us runs WordPress (Elementor) with the public REST API open, so published posts
 * can be pulled with no credentials — Bart writes in WordPress, and the article appears on
 * history.thelearningportal.us in the app's own design, with the app's SEO tags and inside its
 * sitemap. Nothing is written back; this is read-only by design.
 *
 * Everything here fails soft. The marketing site being slow or down must never take the subdomain
 * with it, so a failed fetch logs and returns an empty collection: the articles page then renders
 * its "nothing published yet" state instead of an error.
 */
class WordPressContent
{
    /** Long enough that WordPress is hit rarely, short enough that a new post shows up the same day. */
    private const CACHE_MINUTES = 30;

    private const TIMEOUT_SECONDS = 6;

    public function __construct(
        private readonly ?string $baseUrl = null,
    ) {}

    public function configured(): bool
    {
        return filled($this->url());
    }

    private function url(): ?string
    {
        $url = $this->baseUrl ?? config('services.wordpress.url');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * Published posts, newest first, already cleaned up for display.
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    public function posts(int $limit = 12): \Illuminate\Support\Collection
    {
        return Cache::remember(
            "wordpress.posts.{$limit}",
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->fetch('posts', $limit),
        );
    }

    /** One post by slug, or null. */
    public function post(string $slug): ?array
    {
        $slug = Str::slug($slug);

        if ($slug === '') {
            return null;
        }

        return Cache::remember(
            "wordpress.post.{$slug}",
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->fetch('posts', 1, ['slug' => $slug])->first(),
        );
    }

    /**
     * @param  array<string,string|int>  $query
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function fetch(string $resource, int $limit, array $query = []): \Illuminate\Support\Collection
    {
        if (! $this->configured()) {
            return collect();
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(2, 200, throw: false)
                ->acceptJson()
                ->get("{$this->url()}/wp-json/wp/v2/{$resource}", $query + [
                    'per_page' => max(1, min($limit, 50)),
                    'status' => 'publish',
                    'orderby' => 'date',
                    'order' => 'desc',
                    '_fields' => 'id,slug,date,modified,link,title,excerpt,content,featured_media',
                ]);

            if (! $response->successful()) {
                Log::warning('WordPress content fetch failed', [
                    'resource' => $resource,
                    'status' => $response->status(),
                ]);

                return collect();
            }

            return collect($response->json())
                ->map(fn (array $item) => $this->normalise($item))
                ->filter(fn (array $item) => $this->publishable($item))
                ->values();
        } catch (\Throwable $e) {
            // The marketing site is not a dependency of this one.
            Log::warning('WordPress content unavailable', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @return array<string,mixed> */
    private function normalise(array $item): array
    {
        $title = $this->plain($item['title']['rendered'] ?? '');
        $excerpt = $this->plain($item['excerpt']['rendered'] ?? '');
        $html = (string) ($item['content']['rendered'] ?? '');

        return [
            'id' => (int) ($item['id'] ?? 0),
            'slug' => (string) ($item['slug'] ?? ''),
            'title' => $title,
            'excerpt' => Str::limit($excerpt !== '' ? $excerpt : $this->plain($html), 220),
            'html' => $html,
            'published_at' => isset($item['date']) ? Carbon::parse($item['date']) : null,
            'updated_at' => isset($item['modified']) ? Carbon::parse($item['modified']) : null,
            'source_url' => (string) ($item['link'] ?? ''),
        ];
    }

    /**
     * Elementor seeds a template site with placeholder posts all called "Project Title" and no body.
     * Surfacing those as articles would be worse than showing nothing, so they are filtered out.
     */
    private function publishable(array $item): bool
    {
        if ($item['slug'] === '' || $item['title'] === '') {
            return false;
        }

        if (Str::lower($item['title']) === 'project title') {
            return false;
        }

        return Str::length(trim(strip_tags($item['html']))) >= 200;
    }

    private function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
