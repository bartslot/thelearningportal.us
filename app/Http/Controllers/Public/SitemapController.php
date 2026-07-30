<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\Locales;
use App\Support\Seo;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml, generated from the routes and the published lessons.
 *
 * Each entry lists every language as an xhtml:link alternate, which is how Google is told that the
 * five URLs are one page in five languages rather than five thin duplicates.
 */
class SitemapController extends Controller
{
    private const CACHE_MINUTES = 60;

    public function __invoke(): Response
    {
        $xml = Cache::remember('sitemap.xml', now()->addMinutes(self::CACHE_MINUTES), function (): string {
            $entries = [];

            foreach (['home' => '1.0', 'lessons' => '0.9', 'about' => '0.6'] as $page => $priority) {
                $entries[] = $this->entry($page, [], $priority, 'weekly');
            }

            foreach (Seo::publicLessons()->latest()->get() as $lesson) {
                $entries[] = $this->entry(
                    'lesson',
                    ['slug' => Seo::lessonSlug($lesson)],
                    '0.8',
                    'monthly',
                    $lesson->updated_at?->toAtomString(),
                );
            }

            // Articles come from WordPress. The index only earns a place once something is published
            // there, so an empty feed keeps it out of the sitemap entirely.
            $articles = app(\App\Services\WordPressContent::class)->posts(50);

            if ($articles->isNotEmpty()) {
                $entries[] = $this->entry('articles', [], '0.7', 'weekly');

                foreach ($articles as $article) {
                    $entries[] = $this->entry(
                        'article',
                        ['slug' => $article['slug']],
                        '0.6',
                        'monthly',
                        $article['updated_at']?->toAtomString(),
                    );
                }
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
                .'xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n"
                .implode('', $entries)
                .'</urlset>'."\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** @param array<string,mixed> $params */
    private function entry(string $page, array $params, string $priority, string $changefreq, ?string $lastmod = null): string
    {
        $alternates = '';

        foreach (Locales::codes() as $code) {
            $alternates .= sprintf(
                '    <xhtml:link rel="alternate" hreflang="%s" href="%s"/>'."\n",
                Locales::region($code),
                e(Seo::url($page, $params, $code)),
            );
        }

        // One <url> per language, each carrying the full alternate set, per Google's guidance.
        $xml = '';

        foreach (Locales::codes() as $code) {
            $xml .= '  <url>'."\n"
                .'    <loc>'.e(Seo::url($page, $params, $code)).'</loc>'."\n"
                .($lastmod ? '    <lastmod>'.e($lastmod).'</lastmod>'."\n" : '')
                .'    <changefreq>'.$changefreq.'</changefreq>'."\n"
                .'    <priority>'.$priority.'</priority>'."\n"
                .$alternates
                .'  </url>'."\n";
        }

        return $xml;
    }
}
