<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

/**
 * One image discovered inside a scraped history article, bound to the section
 * (scene beat) it illustrates. This is a DISCOVERY record only — the source site's
 * own copy ($rawUrl) is never re-served; we resolve $title/$creator to the original
 * open-licensed file on Wikimedia Commons before anything is stored or shown.
 */
final class ScrapedImage
{
    public function __construct(
        public readonly string $title,        // caption minus the trailing "(License)"
        public readonly ?string $creator,     // parsed from the caption when a "by X" is present
        public readonly string $licenseRaw,   // e.g. "Public Domain", "CC BY", "CC BY-NC-SA"
        public readonly bool $isFree,          // PD / CC0 / CC BY(-SA) — safe to reuse; NC/ND/© excluded
        public readonly ?string $detailUrl,   // the source's image detail page (provenance)
        public readonly ?string $rawUrl,       // the source-hosted image — reference/thumb only, never re-served
        public readonly string $sectionAnchor, // the h2/h3 id this image sits under
        public readonly string $sectionTitle,
        public readonly int $order,            // document order across the whole article
    ) {}

    /** Best free-text query for resolving this image to a Wikimedia Commons file. */
    public function commonsQuery(): string
    {
        return trim($this->title.' '.($this->creator ?? ''));
    }
}
