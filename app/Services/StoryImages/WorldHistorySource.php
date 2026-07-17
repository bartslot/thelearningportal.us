<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

use DOMElement;
use DOMNode;
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
final class WorldHistorySource extends AbstractDomStorySource
{
    private const BASE = 'https://www.worldhistory.org';

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

    protected function containerSelector(): string
    {
        return '.text';
    }

    protected function lang(): string
    {
        return 'en';
    }

    protected function headingTags(): array
    {
        return ['h2'];
    }

    protected function classifyHeading(DOMElement $heading): array
    {
        $text = trim($heading->textContent);
        foreach (self::NON_CONTENT as $stop) {
            if (strcasecmp($text, $stop) === 0) {
                return ['stop'];
            }
        }

        return ['section', $heading->getAttribute('id') ?: 'sec', $text];
    }

    protected function parseFigure(DOMNode $figure, string $anchor, string $sectionTitle, int $order): ?ScrapedImage
    {
        $fig = new Crawler($figure);

        $imgNode = $fig->filter('img')->first();
        if (! $imgNode->count()) {
            return null;
        }
        $rawUrl = $imgNode->attr('data-src') ?: $imgNode->attr('src');

        $capNode = $fig->filter('figcaption')->first();
        $caption = $capNode->count() ? trim((string) preg_replace('/\s+/', ' ', $capNode->text())) : '';
        if ($caption === '') {
            return null;
        }

        [$title, $creator, $licenseRaw] = $this->parseCaption($caption);

        return new ScrapedImage(
            title: $title,
            creator: $creator,
            licenseRaw: $licenseRaw,
            isFree: self::isFreeLicense($licenseRaw),
            detailUrl: $this->detailUrl($figure, $fig),
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
            if ($n->nodeName === 'a' && $n instanceof DOMElement && $n->getAttribute('href') !== '') {
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

        return str_starts_with($href, 'http') ? $href : self::BASE.'/'.ltrim($href, '/');
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
            $caption = trim((string) preg_replace('/\(([^)]*)\)\s*$/', '', $caption));
        }

        if (preg_match('/^(.*?)\s+by\s+(.+)$/i', $caption, $m)) {
            return [trim($m[1]), trim($m[2]), $license];
        }

        return [$caption, null, $license];
    }
}
