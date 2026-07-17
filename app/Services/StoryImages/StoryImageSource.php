<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

/**
 * A site that publishes history articles whose images are hand-matched to specific
 * events/sections. Implementations parse an article into scene-sized sections with
 * their images; a later stage resolves each image to its open-licensed Commons
 * original and stores an event→image mapping.
 */
interface StoryImageSource
{
    /** Human-readable source key stored on scraped records (e.g. 'worldhistory'). */
    public function key(): string;

    /** Whether this source can handle the given article URL. */
    public function supports(string $url): bool;

    /** Fetch + parse the article, or null if it can't be retrieved/parsed. */
    public function fetch(string $url): ?ScrapedArticle;
}
