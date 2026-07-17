<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

/**
 * A scraped image after it has been resolved to an open-licensed Wikimedia Commons
 * original. This is the only form we ever store or show — it carries the Commons
 * file + license + attribution, plus the scene context (which section it illustrates)
 * and the discovery provenance (which article surfaced it).
 */
final class ResolvedStoryImage
{
    public function __construct(
        // scene placement
        public readonly string $sectionAnchor,
        public readonly string $sectionTitle,
        public readonly int $order,
        // resolved Commons original
        public readonly string $commonsFile,   // "Philip II by Antonio Moro.jpg"
        public readonly string $imageUrl,       // full-res Special:FilePath
        public readonly ?string $thumbUrl,
        public readonly string $filePage,       // Commons File: page
        public readonly string $license,
        public readonly ?string $creator,
        public readonly string $title,
        // discovery provenance
        public readonly string $sourceSite,     // 'worldhistory' | 'historiek'
        public readonly string $sourceCaption,  // the caption we matched on
        public readonly ?string $sourceUrl,     // the source article / image page
    ) {}

    /** A human-readable credit line for the scene's background_credit. */
    public function credit(): string
    {
        return trim(($this->title ?: $this->commonsFile)
            .($this->creator ? ' — '.$this->creator : '')
            .' ('.$this->license.', via Wikimedia Commons)');
    }
}
