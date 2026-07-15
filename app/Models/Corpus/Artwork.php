<?php

declare(strict_types=1);

namespace App\Models\Corpus;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view over public.artworks — public-domain paintings harvested from
 * Wikimedia Commons/Wikidata, linked to corpus QIDs via public.artwork_links.
 * Never written by the app; built by the corpus artwork harvest.
 */
class Artwork extends Model
{
    protected $connection = 'pgsql_corpus';

    protected $table = 'artworks';

    protected $primaryKey = 'qid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'creator_death_year' => 'integer',
        'inception_year' => 'integer',
        'pd_likely' => 'boolean',
        'extra' => 'array',
    ];

    /** Thumbnail URL at ~$width px. Commons FilePath honours ?width=; Europeana provides its
     *  own thumbnail proxy (the raw image_url is a provider CDN that ignores ?width). */
    public function renditionUrl(int $width): string
    {
        if ($this->source === 'europeana') {
            return $this->extra['thumb_url'] ?? $this->image_url;
        }

        return $this->image_url.'?width='.$width;
    }

    /** "Creator, year · Museum" caption line; parts drop out when unknown. */
    public function caption(): string
    {
        $who = collect([$this->creator_name, $this->inception_year])->filter()->implode(', ');

        return collect([$who, $this->collection])->filter()->implode(' · ');
    }

    /**
     * Search by painting title, painter name, or the name of what the painting
     * depicts (via artwork_links.target_label — so "Constantinople" also finds
     * cityscapes whose titles don't contain the word). PD works only.
     */
    public function scopeSearch($query, string $term, int $limit = 30, ?string $kind = null)
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query
            ->where('pd_likely', true)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->where(fn ($q) => $q
                ->where('title', 'ILIKE', $like)
                ->orWhere('creator_name', 'ILIKE', $like)
                ->orWhereIn('qid', fn ($s) => $s
                    ->select('artwork_qid')->from('artwork_links')->where('target_label', 'ILIKE', $like)))
            ->orderByRaw('quality desc nulls last, inception_year desc nulls last')
            ->limit($limit);
    }

    /** Artworks that depict the given Wikidata QID (a corpus figure/polity/place). */
    public function scopeDepicting($query, string $targetQid, int $limit = 30, ?string $kind = null)
    {
        return $query
            ->where('pd_likely', true)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->whereIn('qid', fn ($q) => $q->select('artwork_qid')->from('artwork_links')->where('target_qid', $targetQid))
            ->orderByRaw('quality desc nulls last, inception_year desc nulls last')
            ->limit($limit);
    }

    /**
     * Paintings that depict a moment near a given year — for matching a scene's era
     * (e.g. "16th century" → ~1550). `span` is the half-window in years. PD works only,
     * best (museum-held, famous painter) first.
     */
    public function scopeNearYear($query, int $year, int $span = 60, int $limit = 30, ?string $kind = null)
    {
        return $query
            ->where('pd_likely', true)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->whereNotNull('depicted_year')
            ->whereBetween('depicted_year', [$year - $span, $year + $span])
            ->orderByRaw('abs(depicted_year - ?) asc, quality desc nulls last', [$year])
            ->limit($limit);
    }

    /**
     * Faceted match-scoring for a scene background (Step-3 picker). Two-stage:
     *
     *  GATE   the work MUST carry every `$core` depicts tag (e.g. ['naval-battle']) —
     *         a candidate missing any core tag is the wrong picture and is excluded.
     *  SCORE  SUBJECT decides relevance: 2·(theme hits) + 2·(actor QID hits)
     *         + era: depicted_era == $era ? 2 : era_flexible ? 1 : 0 + quality/50.
     *         Region is only a SOFT nudge (never a gate): a work in one of $preferRegions
     *         gets +2, 'universal' +1, unclassified 0, a foreign region −4. So a foreign-
     *         subject work that's genuinely on-topic (e.g. "Landing of Columbus" for a
     *         colonisation lesson whose regions include 'americas') still surfaces, while an
     *         off-topic American work in a French-Revolution lesson sinks on subject + region.
     *
     * Ranked best-first. Only tagged, public-domain works can match.
     */
    public function scopeMatchScene(
        $query,
        array $core,
        array $themes = [],
        array $actorQids = [],
        ?string $era = null,
        int $limit = 30,
        ?string $kind = null,
        array $preferRegions = ['european'],
    ) {
        $pgArray = fn (array $a): string => '{'.implode(',', array_map(
            fn ($x) => '"'.str_replace(['\\', '"'], '', (string) $x).'"', $a
        )).'}';

        $query
            ->where('pd_likely', true)
            ->whereNotNull('tags')
            ->when($kind, fn ($q) => $q->where('kind', $kind));

        // GATE — every core tag must be present (Postgres array containment).
        if ($core !== []) {
            $query->whereRaw('tags @> ?::text[]', [$pgArray($core)]);
        }

        $themesArr = $pgArray($themes);
        $actorsArr = $pgArray($actorQids);

        return $query
            ->selectRaw(
                'artworks.*, '
                // ranking score (weighted, incl. quality tiebreak)
                .'( 2 * (select count(*) from unnest(tags) t where t = any(?::text[])) '
                .'+ 2 * (select count(distinct al.target_qid) from artwork_links al '
                .'         where al.artwork_qid = artworks.qid and al.target_qid = any(?::text[])) '
                .'+ (case when depicted_era = ? then 2 when era_flexible then 1 else 0 end) '
                .'+ coalesce(quality, 0) / 50.0 '
                // region: soft nudge only — subject already decided relevance above.
                .'+ (case when region = any(?::text[]) then 2 when region = \'universal\' then 1 when region is null then 0 else -4 end) ) as match_score, '
                // soft-criteria hit count, for the "n / m correct" badge
                .'( (select count(*) from unnest(tags) t where t = any(?::text[])) '
                .'+ (select count(distinct al.target_qid) from artwork_links al '
                .'     where al.artwork_qid = artworks.qid and al.target_qid = any(?::text[])) '
                .'+ (case when depicted_era = ? or era_flexible then 1 else 0 end) ) as soft_hits',
                [$themesArr, $actorsArr, $era, $pgArray($preferRegions), $themesArr, $actorsArr, $era],
            )
            ->orderByRaw('match_score desc, quality desc nulls last')
            ->limit($limit);
    }
}
