<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SvgAsset;
use App\Models\User;
use App\Services\Svg\SvgSanitizer;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Finds freely-licensed SVGs on the open web and imports them into a teacher's
 * library — fetch, sanitise ({@see SvgSanitizer}), record the licence, store.
 *
 * No server-side rasterisation or tracing: the ink drawing engine samples the
 * stored SVG's paths and builds its tone map in the browser, so this stays
 * portable to shared hosting with no binary dependencies.
 */
class SvgAssetService
{
    /** Hosts we will fetch bytes from. Blocks SSRF via arbitrary user URLs. */
    private const ALLOWED_HOSTS = [
        'freesvg.org',
        'commons.wikimedia.org',
        'upload.wikimedia.org',
    ];

    private const COMMONS_API = 'https://commons.wikimedia.org/w/api.php';

    private const UA = 'LearningPortal/1.0 (thelearningportal.us; teacher SVG import)';

    private const TIMEOUT = 12;

    public function __construct(private readonly SvgSanitizer $sanitizer) {}

    /**
     * Search Wikimedia Commons for freely-licensed SVG files.
     *
     * @return list<array{source:string, source_ref:string, source_url:string, title:string, license:string, attribution:?string, thumb_url:string, download_url:string}>
     */
    public function searchCommons(string $term, int $limit = 24): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        return Cache::remember('svg.commons.'.md5($term).".{$limit}", 3600,
            fn () => $this->queryCommons($term, $limit));
    }

    /**
     * Import a candidate returned by {@see searchCommons()} into the teacher's library.
     *
     * @param  array{source:string, source_ref:string, source_url:string, title:string, license:string, attribution:?string, download_url:string}  $candidate
     */
    public function import(User $user, array $candidate): SvgAsset
    {
        $raw = $this->fetchRaw($candidate['download_url']);
        $clean = $this->sanitizer->sanitize($raw);

        $path = sprintf('svg-assets/%d/%s.svg', $user->id, Str::uuid()->toString());
        Storage::disk('public')->put($path, $clean['svg']);

        return SvgAsset::updateOrCreate(
            ['user_id' => $user->id, 'source' => $candidate['source'], 'source_ref' => $candidate['source_ref']],
            [
                'source_url' => $candidate['source_url'],
                'title' => $candidate['title'],
                'license' => $candidate['license'],
                'attribution' => $candidate['attribution'] ?? null,
                'svg_path' => $path,
                'width' => $clean['width'],
                'height' => $clean['height'],
                'view_box' => $clean['view_box'],
            ],
        );
    }

    /**
     * Import from a pasted page URL (freesvg.org item, or a direct .svg on an
     * allowed host). freesvg gates downloads behind a session cookie + referer.
     */
    public function importFromUrl(User $user, string $pageUrl): SvgAsset
    {
        $host = $this->assertAllowedHost($pageUrl);

        if ($host === 'freesvg.org' && ! Str::endsWith(parse_url($pageUrl, PHP_URL_PATH) ?? '', '.svg')) {
            return $this->importFreeSvgPage($user, $pageUrl);
        }

        $raw = $this->fetchRaw($pageUrl);
        $clean = $this->sanitizer->sanitize($raw);
        $path = sprintf('svg-assets/%d/%s.svg', $user->id, Str::uuid()->toString());
        Storage::disk('public')->put($path, $clean['svg']);

        return SvgAsset::updateOrCreate(
            ['user_id' => $user->id, 'source' => 'url', 'source_ref' => $pageUrl],
            [
                'source_url' => $pageUrl,
                'title' => Str::of(basename(parse_url($pageUrl, PHP_URL_PATH) ?? 'artwork'))->beforeLast('.')->headline(),
                'license' => 'Unknown — verify manually',
                'attribution' => null,
                'svg_path' => $path,
                'width' => $clean['width'],
                'height' => $clean['height'],
                'view_box' => $clean['view_box'],
            ],
        );
    }

    private function importFreeSvgPage(User $user, string $pageUrl): SvgAsset
    {
        $jar = new CookieJar;
        $page = Http::withHeaders(['User-Agent' => self::UA])
            ->withOptions(['cookies' => $jar])
            ->timeout(self::TIMEOUT)->get($pageUrl);

        if (! $page->successful()) {
            throw new RuntimeException("freesvg page unreachable ({$page->status()}).");
        }
        $html = $page->body();

        if (! preg_match('~href="(/?(?:https?://freesvg\.org)?/download/\d+)"~i', $html, $m)) {
            throw new RuntimeException('No download link found on the freesvg page.');
        }
        $downloadUrl = Str::startsWith($m[1], 'http') ? $m[1] : 'https://freesvg.org'.$m[1];
        $title = preg_match('/property="og:title" content="([^"]*)"/', $html, $t)
            ? html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5) : 'Imported SVG';
        $license = preg_match('/CC0/i', $html) ? 'CC0'
            : (preg_match('/public domain/i', $html) ? 'Public domain' : 'Unknown — verify manually');

        $svg = Http::withHeaders(['User-Agent' => self::UA, 'Referer' => $pageUrl])
            ->withOptions(['cookies' => $jar])
            ->timeout(self::TIMEOUT)->get($downloadUrl);

        if (! $svg->successful() || trim($svg->body()) === '') {
            throw new RuntimeException('freesvg download failed or returned empty.');
        }
        $clean = $this->sanitizer->sanitize($svg->body());
        $path = sprintf('svg-assets/%d/%s.svg', $user->id, Str::uuid()->toString());
        Storage::disk('public')->put($path, $clean['svg']);

        return SvgAsset::updateOrCreate(
            ['user_id' => $user->id, 'source' => 'freesvg', 'source_ref' => $pageUrl],
            [
                'source_url' => $pageUrl,
                'title' => $title,
                'license' => $license,
                'attribution' => null,
                'svg_path' => $path,
                'width' => $clean['width'],
                'height' => $clean['height'],
                'view_box' => $clean['view_box'],
            ],
        );
    }

    /** @return list<array{source:string, source_ref:string, source_url:string, title:string, license:string, attribution:?string, thumb_url:string, download_url:string}> */
    private function queryCommons(string $term, int $limit): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(self::TIMEOUT)
                ->get(self::COMMONS_API, [
                    'action' => 'query', 'format' => 'json',
                    'generator' => 'search',
                    'gsrsearch' => "filemime:image/svg+xml {$term}",
                    'gsrnamespace' => 6,
                    'gsrlimit' => min($limit * 2, 50),
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|extmetadata',
                    'iiurlwidth' => 240,
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
            $fileTitle = preg_replace('/^File:/', '', (string) $page['title']);
            $out[] = [
                'source' => 'commons',
                'source_ref' => $fileTitle,
                'source_url' => $info['descriptionurl'] ?? ('https://commons.wikimedia.org/wiki/File:'.rawurlencode($fileTitle)),
                'title' => $this->cleanText($meta['ObjectName']['value'] ?? pathinfo($fileTitle, PATHINFO_FILENAME)),
                'license' => $license,
                'attribution' => $this->cleanText($meta['Artist']['value'] ?? '') ?: null,
                'thumb_url' => $info['thumburl'] ?? '',
                'download_url' => 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($fileTitle),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function fetchRaw(string $url): string
    {
        $this->assertAllowedHost($url);
        $response = Http::withHeaders(['User-Agent' => self::UA])->timeout(self::TIMEOUT)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Fetch failed ({$response->status()}) for {$url}.");
        }

        return $response->body();
    }

    private function assertAllowedHost(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException("Host not allowed: {$host}.");
        }

        return $host;
    }

    private function isFreeLicense(string $license): bool
    {
        if ($license === '' || preg_match('/\b(NC|ND)\b/i', $license)) {
            return false;
        }

        return (bool) preg_match('/public domain|^PD|CC0|CC[ -]BY/i', $license);
    }

    private function cleanText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
