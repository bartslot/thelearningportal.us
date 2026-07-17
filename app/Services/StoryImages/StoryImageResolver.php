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

    public function __construct(
        private readonly CommonsImageService $commons,
        private readonly WikidataEntityResolver $wikidata,
    ) {}

    public function resolve(ScrapedImage $img, string $sourceSite = '', string $lang = 'en'): ?ResolvedStoryImage
    {
        // QID-anchored first (precise: the subject's own portrait / a depicts-tagged
        // image), then fall back to full-text Commons search.
        $hit = $this->qidMatch($img, $lang) ?? $this->bestMatch($img);
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
            title: ResolvedStoryImage::cleanCommonsTitle((string) ($hit['title'] ?? $img->title)),
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
            $r = $this->resolve($img, $article->source, $article->lang);
            if ($r !== null) {
                $resolved[] = $r;
            }
            usleep(self::THROTTLE_US);
        }

        return $resolved;
    }

    /**
     * Resolve the caption's SUBJECT to a Wikidata entity, then take that entity's
     * canonical image (P18 — e.g. a person's portrait) or a Commons file that
     * structurally depicts it (P180). Both come back license-filtered.
     *
     * @return array<string,mixed>|null
     */
    private function qidMatch(ScrapedImage $img, string $lang): ?array
    {
        foreach ($this->subjectQueries($img) as $subject) {
            $qid = $this->wikidata->qidFor($subject, $lang);
            if ($qid === null) {
                continue;
            }

            $file = $this->wikidata->imageFile($qid);
            if ($file !== null && ($meta = $this->commons->fileMeta($file)) !== null) {
                return $meta;
            }

            $depicts = $this->commons->searchDepicting($qid, 3);
            if ($depicts !== []) {
                return $depicts[0];
            }
        }

        return null;
    }

    /**
     * Subject-only phrases for entity lookup — the lead clause (the person/place name),
     * then the whole title. Deliberately excludes the creator so "Philip II of Spain by
     * Moro" resolves to Philip II, not the painter Antonio Moro.
     *
     * @return list<string>
     */
    private function subjectQueries(ScrapedImage $img): array
    {
        $lead = trim((string) preg_split('/[,.;:–—-]/u', $img->title, 2)[0]);

        return array_values(array_unique(array_filter([$lead, $img->title])));
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
            $wanted = ResolvedStoryImage::contentTokens($query);
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
        $haystack = ResolvedStoryImage::contentTokens(($hit['title'] ?? '').' '.($hit['file_title'] ?? '').' '.($hit['artist'] ?? ''));
        if ($haystack === []) {
            return false;
        }

        return array_intersect($wanted, $haystack) !== [];
    }
}
