<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

use DOMNode;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

/**
 * World History Encyclopedia (worldhistory.org) article parser.
 *
 * WHE marks up articles with <h2> section headings (the scene boundaries) and
 * <figure><img><figcaption> blocks whose caption ends in the underlying work's
 * license — "(Public Domain)", "(CC BY)". We use the site only to DISCOVER which
 * image illustrates which section; the actual file is later resolved to its
 * Wikimedia Commons original. WHE's own CC BY-NC-SA copy is never re-served.
 */
final class WorldHistorySource implements StoryImageSource
{
    private const BASE = 'https://www.worldhistory.org';
    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com)';
    private const TIMEOUT = 20;

    /** h2 headings that mark the end of article content — stop scraping here. */
    private const NON_CONTENT = [
        'Contents', 'Bibliography', 'About the Author', 'Translations',
        'Questions & Answers', 'Related Content', 'Cite This Work',
        'License & Copyright', 'Free for the World, Supported by You',
        'Timeline', 'Newsletter',
    ];

    public function key(): string
    {
        return 'worldhistory';
    }

    public function supports(string $url): bool
    {
        return str_contains((string) parse_url($url, PHP_URL_HOST), 'worldhistory.org');
    }

    public function fetch(string $url): ?ScrapedArticle
    {
        $res = Http::timeout(self::TIMEOUT)->withUserAgent(self::UA)->get($url);
        if (! $res->ok()) {
            return null;
        }
        $html = $res->body();
        if (str_contains($html, 'Just a moment') || str_contains($html, 'cf-browser-verification')) {
            return null;   // Cloudflare interstitial — not the article
        }

        $doc = new Crawler($html, $url);
        $body = $doc->filter('.text')->first();
        if (! $body->count()) {
            return null;
        }

        $h1 = $doc->filter('h1')->first();
        $title = $h1->count() ? trim($h1->text()) : $url;

        $sections = $this->walk($body->getNode(0));

        return new ScrapedArticle($url, $this->key(), 'en', $title, $sections);
    }

    /**
     * Walk the article body in document order, opening a new section at each content
     * <h2> and attaching text (word counts) + <figure> images to the current one.
     *
     * @return list<ScrapedSection>
     */
    private function walk(DOMNode $root): array
    {
        $sections = [];
        $cur = ['anchor' => 'intro', 'title' => 'Introduction', 'words' => 0, 'images' => []];
        $order = 0;
        $stop = false;

        $visit = function (DOMNode $node) use (&$visit, &$sections, &$cur, &$order, &$stop): void {
            foreach ($node->childNodes as $child) {
                if ($stop) {
                    return;
                }
                $name = $child->nodeName;

                if ($child->nodeType === XML_ELEMENT_NODE && $name === 'h2') {
                    $heading = trim($child->textContent);
                    if ($this->isNonContent($heading)) {
                        $stop = true;
                        return;
                    }
                    if ($cur['words'] > 0 || $cur['images'] !== []) {
                        $sections[] = $this->section($cur);
                    }
                    $cur = [
                        'anchor' => $child instanceof \DOMElement ? ($child->getAttribute('id') ?: 'sec') : 'sec',
                        'title' => $heading,
                        'words' => 0,
                        'images' => [],
                    ];
                } elseif ($child->nodeType === XML_ELEMENT_NODE && $name === 'figure') {
                    $img = $this->parseFigure($child, $cur['anchor'], $cur['title'], $order);
                    if ($img !== null) {
                        $cur['images'][] = $img;
                        $order++;
                    }
                    // do not recurse into the figure
                } elseif ($child->nodeType === XML_TEXT_NODE) {
                    $cur['words'] += str_word_count((string) $child->nodeValue);
                } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                    $visit($child);
                }
            }
        };

        $visit($root);
        if ($cur['words'] > 0 || $cur['images'] !== []) {
            $sections[] = $this->section($cur);
        }

        return $sections;
    }

    /** @param array{anchor:string,title:string,words:int,images:list<ScrapedImage>} $c */
    private function section(array $c): ScrapedSection
    {
        return new ScrapedSection($c['anchor'], $c['title'], $c['words'], $c['images']);
    }

    private function parseFigure(DOMNode $figure, string $anchor, string $sectionTitle, int $order): ?ScrapedImage
    {
        $fig = new Crawler($figure);

        $imgNode = $fig->filter('img')->first();
        if (! $imgNode->count()) {
            return null;
        }
        $rawUrl = $imgNode->attr('data-src') ?: $imgNode->attr('src');

        $capNode = $fig->filter('figcaption')->first();
        $caption = $capNode->count() ? trim(preg_replace('/\s+/', ' ', $capNode->text())) : '';
        if ($caption === '') {
            return null;
        }

        // WHE wraps the whole <figure> in an <a href="/image/…"> — the detail page — so the
        // link is the figure's ancestor, not a child. Fall back to any child <a>.
        $detailUrl = $this->detailUrl($figure, $fig);

        [$title, $creator, $licenseRaw] = $this->parseCaption($caption);

        return new ScrapedImage(
            title: $title,
            creator: $creator,
            licenseRaw: $licenseRaw,
            isFree: self::isFreeLicense($licenseRaw),
            detailUrl: $detailUrl,
            rawUrl: $rawUrl,
            sectionAnchor: $anchor,
            sectionTitle: $sectionTitle,
            order: $order,
        );
    }

    /** The WHE image detail page: the figure's ancestor <a>, else a child <a>. */
    private function detailUrl(DOMNode $figure, Crawler $fig): ?string
    {
        for ($n = $figure->parentNode; $n !== null; $n = $n->parentNode) {
            if ($n->nodeName === 'a' && $n instanceof \DOMElement && $n->getAttribute('href') !== '') {
                return $this->absolute($n->getAttribute('href'));
            }
            if ($n->nodeName === 'body') {
                break;
            }
        }

        $child = $fig->filter('a')->first();

        return $child->count() ? $this->absolute($child->attr('href') ?? '') : null;
    }

    private function absolute(string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, 'http')) {
            return $href;
        }

        return self::BASE.'/'.ltrim($href, '/');
    }

    /**
     * WHE captions read "<Work Title> <Creator> (License)", sometimes "<Title> by <X>".
     * Pull the trailing "(License)", then split off a "by <Creator>" if present.
     *
     * @return array{0:string,1:?string,2:string}
     */
    private function parseCaption(string $caption): array
    {
        $license = 'Unknown';
        if (preg_match('/\(([^)]*)\)\s*$/', $caption, $m)) {
            $license = trim($m[1]);
            $caption = trim(preg_replace('/\(([^)]*)\)\s*$/', '', $caption));
        }

        $creator = null;
        if (preg_match('/^(.*?)\s+by\s+(.+)$/i', $caption, $m)) {
            $title = trim($m[1]);
            $creator = trim($m[2]);
        } else {
            $title = $caption;
        }

        return [$title, $creator, $license];
    }

    /** PD / CC0 / CC BY(-SA) are reusable; NC, ND, © and unknowns are not. */
    public static function isFreeLicense(string $raw): bool
    {
        $l = strtolower($raw);
        if (str_contains($l, 'nc') || str_contains($l, 'nd')
            || str_contains($l, '©') || str_contains($l, 'copyright')
            || str_contains($l, 'all rights')) {
            return false;
        }

        return str_contains($l, 'public domain')
            || str_contains($l, 'cc0')
            || str_contains($l, 'cc by');
    }

    private function isNonContent(string $heading): bool
    {
        foreach (self::NON_CONTENT as $stop) {
            if (strcasecmp($heading, $stop) === 0) {
                return true;
            }
        }

        return false;
    }
}
