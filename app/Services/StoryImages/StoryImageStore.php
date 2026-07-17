<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

use Illuminate\Support\Facades\DB;

/**
 * Persists resolved story images into the corpus so the scene picker surfaces them.
 *
 * Each image becomes an `artworks` row (qid = "commons:<file>") plus an
 * `artwork_links` row (target_qid = the lesson's event QID) — the exact shape the
 * Step-3 picker already reads. Only open-licensed Commons originals reach here.
 */
final class StoryImageStore
{
    private const CONNECTION = 'pgsql_corpus';

    /**
     * Store one resolved image against an event; returns the artwork qid.
     */
    public function store(ResolvedStoryImage $img, string $eventQid, string $eventName): string
    {
        $qid = 'commons:'.$img->commonsFile;
        $pd = $this->isPublicDomain($img->license);

        DB::connection(self::CONNECTION)->table('artworks')->updateOrInsert(
            ['qid' => $qid],
            [
                'title' => $img->title,
                'creator_name' => $img->creator,
                'image_url' => $img->imageUrl,
                'source' => 'story:'.$img->sourceSite,
                'source_uri' => $img->filePage,
                'license_note' => $img->license,
                'pd_likely' => $pd,
                'kind' => 'painting',
                'quality' => 70,   // hand-matched → rank near the top of the grid
                'extra' => json_encode([
                    'thumb_url' => $img->thumbUrl,
                    'credit' => $img->credit(),
                    'section_title' => $img->sectionTitle,
                    'section_anchor' => $img->sectionAnchor,
                    'order' => $img->order,
                    'discovered_via' => $img->sourceSite,
                    'source_caption' => $img->sourceCaption,
                    'source_url' => $img->sourceUrl,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ],
        );

        // Link to the event (idempotent on the artwork+target pair).
        DB::connection(self::CONNECTION)->table('artwork_links')->updateOrInsert(
            ['artwork_qid' => $qid, 'target_qid' => $eventQid],
            ['target_label' => $eventName],
        );

        return $qid;
    }

    private function isPublicDomain(string $license): bool
    {
        $l = strtolower($license);

        return str_contains($l, 'public domain') || str_contains($l, 'cc0');
    }
}
