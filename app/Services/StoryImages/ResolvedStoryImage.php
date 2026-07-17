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

    /**
     * Commons ObjectName metadata often embeds Wikidata monolingual markup
     * ('Portrait of X title QS:P1476,en:"…"label QS:…'). Keep only the clean lead.
     */
    public static function cleanCommonsTitle(string $title): string
    {
        $title = (string) preg_split('/\s*(?:title|label)\s+QS:/u', $title, 2)[0];

        return trim((string) preg_replace('/\s+/', ' ', $title));
    }

    /**
     * Diacritic-insensitive, lower-cased content words (length > 3, minus a few
     * generic connectors) — used for topical overlap tests.
     *
     * @return list<string>
     */
    public static function contentTokens(string $text): array
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $ascii = strtolower($ascii !== false ? $ascii : $text);
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stop = ['with', 'from', 'this', 'that', 'into', 'voor', 'naar', 'door', 'deze', 'zijn', 'over', 'onze', 'komst'];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w) => strlen($w) > 3 && ! in_array($w, $stop, true),
        )));
    }
}
