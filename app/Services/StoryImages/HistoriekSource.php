<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

use DOMElement;
use DOMNode;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Historiek.net (Dutch history) article parser.
 *
 * Historiek marks sections with <h3 id="htoc-…"> (auto-generated table-of-contents
 * anchors) inside .entry-content; the first non-htoc h3 begins the trailing "related"
 * block, so we stop there. Unlike WHE, captions carry no license/creator and images
 * are self-hosted — the caption is a plain SUBJECT description ("Jan van der Does",
 * "De humanist Justus Lipsius"). So historiek images are never trusted as free here;
 * every one is left for the Commons resolver to match to an open original by subject.
 * Historiek's Dutch prose is fully copyrighted and is not reused — only the facts and
 * the section structure inform the lesson.
 */
final class HistoriekSource extends AbstractDomStorySource
{
    public function key(): string
    {
        return 'historiek';
    }

    public function supports(string $url): bool
    {
        return str_contains((string) parse_url($url, PHP_URL_HOST), 'historiek.net');
    }

    protected function containerSelector(): string
    {
        return '.entry-content';
    }

    protected function lang(): string
    {
        return 'nl';
    }

    protected function headingTags(): array
    {
        return ['h3'];
    }

    protected function classifyHeading(DOMElement $heading): array
    {
        $id = $heading->getAttribute('id');
        // Only TOC-anchored headings are real article sections; the first bare h3 marks
        // the start of the sidebar/related block that WordPress appends to the content.
        if (str_starts_with($id, 'htoc-')) {
            return ['section', $id, trim($heading->textContent)];
        }

        return ['stop'];
    }

    protected function parseFigure(DOMNode $figure, string $anchor, string $sectionTitle, int $order): ?ScrapedImage
    {
        $fig = new Crawler($figure);

        $imgNode = $fig->filter('img')->first();
        if (! $imgNode->count()) {
            return null;
        }

        $capNode = $fig->filter('figcaption')->first();
        $caption = $capNode->count() ? trim((string) preg_replace('/\s+/', ' ', $capNode->text())) : '';
        if ($caption === '') {
            return null;   // no subject to resolve against
        }

        return new ScrapedImage(
            title: $caption,          // the caption is a subject query for Commons
            creator: null,
            licenseRaw: 'Unknown',    // never asserted on the page
            isFree: false,             // the Commons resolver is the sole gate for historiek
            detailUrl: null,
            rawUrl: $imgNode->attr('data-src') ?: $imgNode->attr('src'),
            sectionAnchor: $anchor,
            sectionTitle: $sectionTitle,
            order: $order,
        );
    }
}
