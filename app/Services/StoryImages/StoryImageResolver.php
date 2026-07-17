<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

use App\Services\CommonsImageService;

/**
 * Turns discovery records (ScrapedImage) into open-licensed Commons originals.
 *
 * The scraped caption is used only as a SEARCH QUERY against Wikimedia Commons —
 * we never re-serve the source site's copy. CommonsImageService already filters to
 * free licenses (PD/CC0/CC-BY[-SA]), so anything it returns is safe to store and show.
 * Images that don't resolve to a free Commons file are dropped.
 */
final class StoryImageResolver
{
    /** Politeness delay between Commons lookups when resolving a whole article (µs). */
    private const THROTTLE_US = 200_000;

    public function __construct(private readonly CommonsImageService $commons) {}

    public function resolve(ScrapedImage $img, string $sourceSite = ''): ?ResolvedStoryImage
    {
        $hit = $this->bestMatch($img);
        if ($hit === null) {
            return null;
        }

        return new ResolvedStoryImage(
            sectionAnchor: $img->sectionAnchor,
            sectionTitle: $img->sectionTitle,
            order: $img->order,
            commonsFile: (string) ($hit['file_title'] ?? ''),
            imageUrl: (string) ($hit['image_url'] ?? ''),
            thumbUrl: $hit['thumb_url'] ?? null,
            filePage: (string) ($hit['file_page'] ?? ''),
            license: (string) ($hit['license'] ?? 'Unknown'),
            creator: $hit['artist'] ?? $img->creator,
            title: (string) ($hit['title'] ?? $img->title),
            sourceSite: $sourceSite,
            sourceCaption: $img->title,
            sourceUrl: $img->detailUrl,
        );
    }

    /**
     * Resolve every image in an article, dropping ones with no free Commons match.
     * Throttled to be polite to the Commons API.
     *
     * @return list<ResolvedStoryImage>
     */
    public function resolveArticle(ScrapedArticle $article): array
    {
        $resolved = [];
        foreach ($article->images() as $img) {
            $r = $this->resolve($img, $article->source);
            if ($r !== null) {
                $resolved[] = $r;
            }
            usleep(self::THROTTLE_US);
        }

        return $resolved;
    }

    /**
     * Try progressively looser queries (full "title + creator" → lead clause → title)
     * and return the first Commons hit that actually shares a word with the caption.
     * The token-overlap guard rejects full-text false positives (a descriptive caption
     * like "Universiteit van Franeker" otherwise pulls any print that shares stop-words).
     *
     * @return array<string,mixed>|null
     */
    private function bestMatch(ScrapedImage $img): ?array
    {
        foreach ($this->candidateQueries($img) as $query) {
            $wanted = $this->tokens($query);
            if ($wanted === []) {
                continue;
            }
            foreach ($this->commons->searchText($query, 4) as $hit) {
                if ($this->sharesToken($wanted, $hit)) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * Ordered, de-duplicated search queries: most specific first. The "lead clause"
     * (text before the first comma/period/dash) turns a sentence-caption into a name.
     *
     * @return list<string>
     */
    private function candidateQueries(ScrapedImage $img): array
    {
        $lead = trim((string) preg_split('/[,.;:–—-]/u', $img->title, 2)[0]);

        $queries = array_filter(array_map('trim', [
            $img->commonsQuery(),
            $lead !== $img->title ? $lead.' '.($img->creator ?? '') : '',
            $lead,
            $img->title,
        ]));

        return array_values(array_unique($queries));
    }

    /** @param list<string> $wanted @param array<string,mixed> $hit */
    private function sharesToken(array $wanted, array $hit): bool
    {
        $haystack = $this->tokens(($hit['title'] ?? '').' '.($hit['file_title'] ?? '').' '.($hit['artist'] ?? ''));
        if ($haystack === []) {
            return false;
        }

        return array_intersect($wanted, $haystack) !== [];
    }

    /**
     * Diacritic-insensitive, lower-cased content words (length > 3, minus a few
     * generic connectors) for overlap testing.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
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
