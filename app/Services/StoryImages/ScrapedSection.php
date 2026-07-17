<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

/**
 * A semantic section of a scraped article — the natural scene boundary. Its word
 * count drives the per-scene narration budget (see ScrapedArticle::sceneBudget()).
 */
final class ScrapedSection
{
    /** @param list<ScrapedImage> $images */
    public function __construct(
        public readonly string $anchor,   // h2/h3 id, or 'intro' for the lead
        public readonly string $title,
        public readonly int $wordCount,
        public readonly array $images,
    ) {}
}
