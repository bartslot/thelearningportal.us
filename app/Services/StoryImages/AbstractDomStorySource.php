<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Shared HTML→ScrapedArticle machinery for story sources: fetch, locate the article
 * container, and walk it in document order — opening a section at each heading and
 * binding <figure> images to the section they sit under. Subclasses supply the
 * site-specific bits (container selector, which headings open/stop a section, and
 * how a figure's caption maps to a ScrapedImage).
 */
abstract class AbstractDomStorySource implements StoryImageSource
{
    protected const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com)';
    protected const TIMEOUT = 20;

    abstract public function key(): string;

    abstract public function supports(string $url): bool;

    /** CSS selector for the element wrapping the article body. */
    abstract protected function containerSelector(): string;

    /** ISO language of this source's articles ('en', 'nl'). */
    abstract protected function lang(): string;

    /** Element tag names to treat as section headings, e.g. ['h2'] or ['h3']. */
    abstract protected function headingTags(): array;

    /**
     * Classify a heading element:
     *   ['section', anchor, title] — open a new section
     *   ['stop']                    — end of article content; stop walking
     *   ['skip']                    — ignore this heading, keep the current section
     *
     * @return array{0:string,1?:string,2?:string}
     */
    abstract protected function classifyHeading(DOMElement $heading): array;

    /** Build a ScrapedImage from a <figure>, or null to drop it. */
    abstract protected function parseFigure(DOMNode $figure, string $anchor, string $sectionTitle, int $order): ?ScrapedImage;

    public function fetch(string $url): ?ScrapedArticle
    {
        $html = $this->getHtml($url);
        if ($html === null) {
            return null;
        }

        $doc = new Crawler($html, $url);
        $body = $doc->filter($this->containerSelector())->first();
        if (! $body->count()) {
            return null;
        }

        $h1 = $doc->filter('h1')->first();
        $title = $h1->count() ? trim($h1->text()) : $url;

        return new ScrapedArticle($url, $this->key(), $this->lang(), $title, $this->walk($body->getNode(0)));
    }

    protected function getHtml(string $url): ?string
    {
        $res = Http::timeout(static::TIMEOUT)->withUserAgent(static::UA)->get($url);
        if (! $res->ok()) {
            return null;
        }
        $html = $res->body();
        if (str_contains($html, 'Just a moment') || str_contains($html, 'cf-browser-verification')) {
            return null;   // Cloudflare interstitial — not the article
        }

        return $html;
    }

    /** @return list<ScrapedSection> */
    protected function walk(DOMNode $root): array
    {
        $headingTags = $this->headingTags();
        $sections = [];
        $cur = ['anchor' => 'intro', 'title' => 'Introduction', 'words' => 0, 'images' => []];
        $order = 0;
        $stop = false;

        $flush = function () use (&$sections, &$cur): void {
            if ($cur['words'] > 0 || $cur['images'] !== []) {
                $sections[] = new ScrapedSection($cur['anchor'], $cur['title'], $cur['words'], $cur['images']);
            }
        };

        $visit = function (DOMNode $node) use (&$visit, &$flush, &$cur, &$order, &$stop, $headingTags): void {
            foreach ($node->childNodes as $child) {
                if ($stop) {
                    return;
                }

                if ($child->nodeType === XML_ELEMENT_NODE && in_array($child->nodeName, $headingTags, true)) {
                    $verdict = $this->classifyHeading($child);
                    if ($verdict[0] === 'stop') {
                        $stop = true;
                        return;
                    }
                    if ($verdict[0] === 'section') {
                        $flush();
                        $cur = ['anchor' => $verdict[1] ?? 'sec', 'title' => $verdict[2] ?? '', 'words' => 0, 'images' => []];
                    }
                    // 'skip' falls through, leaving the current section unchanged
                } elseif ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'figure') {
                    $img = $this->parseFigure($child, $cur['anchor'], $cur['title'], $order);
                    if ($img !== null) {
                        $cur['images'][] = $img;
                        $order++;
                    }
                    // never recurse into a figure
                } elseif ($child->nodeType === XML_TEXT_NODE) {
                    $cur['words'] += str_word_count((string) $child->nodeValue);
                } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                    $visit($child);
                }
            }
        };

        $visit($root);
        $flush();

        return $sections;
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
}
