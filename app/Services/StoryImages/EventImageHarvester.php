<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

use App\Services\CommonsImageService;

/**
 * Harvests open-licensed imagery for an event straight from Wikimedia Commons by
 * NAME + aliases — the fallback for events that lack structured "depicts" (P180)
 * data, which turns out to be most of them (e.g. Black Death: 0 depicts, 36 by name).
 *
 * Unlike StoryImageResolver's scraped-caption path, this TRUSTS Commons' own
 * relevance ranking: the query is the topic itself, so the top hits are on-subject
 * even when the filename is opaque (the classic plague miniature "Doutielt3.jpg"
 * has no matchable words but is exactly right). Only the free-license filter applies.
 */
final class EventImageHarvester
{
    /** Max alias queries to run beyond the primary name (keeps API calls bounded). */
    private const MAX_ALIASES = 4;

    public function __construct(private readonly CommonsImageService $commons) {}

    /**
     * @param  string  $eventQid  the event's Wikidata QID — its structurally "depicts"-tagged
     *                             images (P180) lead, before the name/alias text fallback
     * @return list<ResolvedStoryImage> up to $limit distinct free Commons images
     */
    public function harvest(string $eventQid, string $eventName, ?string $aliases, int $limit = 10): array
    {
        // Topic vocabulary from the event name + aliases; a modern licensed photo must
        // hit one of these words, PD historical works get in regardless (their filenames
        // are often opaque — "Doutielt3.jpg" is a genuine plague miniature).
        $topic = ResolvedStoryImage::contentTokens($eventName.' '.($aliases ? str_replace('|', ' ', $aliases) : ''));

        $seenFile = [];
        $seenTitle = [];
        $out = [];

        // (1) Structured "depicts" hits first — precise when the data exists. (2) Then the
        // name/alias full-text fallback, which covers the many events with no P180 data.
        $depicts = $eventQid !== '' ? $this->commons->searchDepicting($eventQid, $limit) : [];
        $batches = array_merge(
            [$depicts],
            array_map(fn (string $q) => $this->commons->searchText($q, $limit), $this->queries($eventName, $aliases)),
        );

        foreach ($batches as $index => $hits) {
            $structured = $index === 0;   // depicts batch is trusted without the topic guard
            foreach ($hits as $hit) {
                $file = (string) ($hit['file_title'] ?? '');
                if ($file === '' || isset($seenFile[$file])) {
                    continue;
                }
                $img = $this->toResolved($hit, $eventName);

                $titleKey = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $img->title));
                if ($titleKey !== '' && isset($seenTitle[$titleKey])) {
                    continue;   // collapse near-identical scans (e.g. 4× the same Boccaccio miniature)
                }
                if (! $structured && ! $this->isOnTopic($img, $topic)) {
                    continue;   // drop off-topic modern photos (e.g. the band "Darkthrone")
                }

                $seenFile[$file] = true;
                $seenTitle[$titleKey] = true;
                $out[] = $img;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * PD/CC0 works are trusted (opaque period-artwork filenames). Anything under a
     * modern photo licence must share a topic word with the event.
     *
     * @param  list<string>  $topic
     */
    private function isOnTopic(ResolvedStoryImage $img, array $topic): bool
    {
        $license = strtolower($img->license);
        if (str_contains($license, 'public domain') || str_contains($license, 'cc0')) {
            return true;
        }
        if ($topic === []) {
            return true;
        }

        $hit = ResolvedStoryImage::contentTokens($img->title.' '.$img->commonsFile);

        return array_intersect($topic, $hit) !== [];
    }

    /** @return list<string> the primary name first, then a few aliases */
    private function queries(string $name, ?string $aliases): array
    {
        $extra = $aliases
            ? array_slice(array_filter(array_map('trim', explode('|', $aliases))), 0, self::MAX_ALIASES)
            : [];

        return array_values(array_unique(array_filter(array_merge([$name], $extra))));
    }

    /** @param array<string,mixed> $hit */
    private function toResolved(array $hit, string $eventName): ResolvedStoryImage
    {
        return new ResolvedStoryImage(
            sectionAnchor: 'general',
            sectionTitle: 'General',
            order: 0,
            commonsFile: (string) ($hit['file_title'] ?? ''),
            imageUrl: (string) ($hit['image_url'] ?? ''),
            thumbUrl: $hit['thumb_url'] ?? null,
            filePage: (string) ($hit['file_page'] ?? ''),
            license: (string) ($hit['license'] ?? 'Unknown'),
            creator: $hit['artist'] ?? null,
            title: ResolvedStoryImage::cleanCommonsTitle((string) ($hit['title'] ?? $hit['file_title'] ?? '')),
            sourceSite: 'commons',
            sourceCaption: $eventName,
            sourceUrl: $hit['file_page'] ?? null,
        );
    }
}
