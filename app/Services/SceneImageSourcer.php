<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Corpus\Artwork;
use App\Models\Corpus\Topic;
use App\Support\PortraitFocus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Find a good, large, openly-licensed historical image for a scene — the way a teacher would.
 *
 * Two libraries, tried in order of how well each suits the subject:
 *   1. the PAINTINGS CORPUS (≈12k curated artworks, ranked by quality + era proximity) — the right
 *      source for anything before photography;
 *   2. WIKIMEDIA COMMONS search — the right source for 20th-century topics, buildings and objects,
 *      and the fallback whenever the corpus has no match.
 *
 * Everything is returned at the largest available rendering; the caller can then run the local
 * Upscayl pass (`lessons:enhance-images`) on anything still short of stage resolution.
 */
class SceneImageSourcer
{
    private const UA = 'LearningPortal/1.0 (https://thelearningportal.us; classroom lesson imagery)';

    /** Commons renders thumbnails up to this width; beyond it we take the original file. */
    private const TARGET_WIDTH = 2000;

    /**
     * Resolve one image for a scene.
     *
     * @param  string  $query  subject to search for, e.g. "Rembrandt self portrait"
     * @param  int|null  $year  target era — pulls corpus results toward contemporaneous artwork
     * @param  'auto'|'art'|'photo'  $prefer  which library to consult first
     * @return array{url:string,credit:string,title:string,source:string}|null
     */
    public function find(string $query, ?int $year = null, string $prefer = 'auto'): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        // A spec can PIN an exact Commons file ("commons:Some file.jpg") when the author already
        // knows the right image — the same judgement a teacher exercises in the Paintings panel.
        if (str_starts_with($query, 'commons:')) {
            return $this->exactCommonsFile(substr($query, 8));
        }

        // Pre-photography subjects are far better served by the painted corpus.
        $artFirst = $prefer === 'art' || ($prefer === 'auto' && $year !== null && $year < 1880);

        $order = $artFirst ? ['corpus', 'commons'] : ['commons', 'corpus'];
        if ($prefer === 'art') {
            $order = ['corpus', 'commons'];
        }
        if ($prefer === 'photo') {
            $order = ['commons', 'corpus'];
        }

        // Try the full phrase, then progressively broader ones. A specific query like
        // "Amsterdam grachtengordel herenhuis" can match nothing, while "Amsterdam grachtengordel"
        // matches plenty — this is what a teacher does when a search comes back empty.
        foreach ($this->broadenings($query) as $attempt) {
            foreach ($order as $source) {
                $hit = $source === 'corpus' ? $this->fromCorpus($attempt, $year) : $this->fromCommons($attempt);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * Progressively shorter versions of a query: the whole phrase, then its first words.
     *
     * @return list<string>
     */
    private function broadenings(string $query): array
    {
        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $attempts = [$query];

        for ($keep = count($words) - 1; $keep >= 2; $keep--) {
            $attempts[] = implode(' ', array_slice($words, 0, $keep));
        }

        return array_values(array_unique($attempts));
    }

    /**
     * Best artwork from the corpus: title/tag match, ranked by curated quality and — when a year is
     * given — by how close the work sits to that era.
     *
     * @return array{url:string,credit:string,title:string,source:string}|null
     */
    public function fromCorpus(string $query, ?int $year = null): ?array
    {
        try {
            $artwork = Topic::resilient(function () use ($query, $year) {
                // Match on WORDS, not the whole phrase: a teacher typing "Rembrandt self portrait"
                // into the Paintings panel expects the Rembrandt portraits, and no artwork is
                // literally titled that. Each meaningful word must appear in title/creator/tags or
                // in what the work is recorded as DEPICTING (artwork_links.target_label — that's how
                // an untitled cityscape still answers "Constantinople").
                $words = collect(preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY))
                    ->reject(fn ($w) => mb_strlen($w) < 3)
                    ->take(4);

                $q = Artwork::query()
                    ->whereNotNull('image_url')
                    ->where('pd_likely', true)          // licensing gate — never ship a non-PD work
                    ->where(fn ($x) => $x->where('quality', '>=', 50)->orWhereNull('quality'));

                foreach ($words as $word) {
                    // Escape the LIKE metacharacters, backslash FIRST or the escapes get re-escaped.
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word).'%';
                    $q->where(function ($w) use ($like) {
                        $w->where('title', 'ILIKE', $like)
                            ->orWhere('creator_name', 'ILIKE', $like)
                            ->orWhereRaw('array_to_string(tags, \' \') ILIKE ?', [$like])
                            ->orWhereIn('qid', fn ($s) => $s->select('artwork_qid')
                                ->from('artwork_links')->where('target_label', 'ILIKE', $like));
                    });
                }

                // Rank: era proximity first (a work SHOWING the period beats one merely painted
                // near it), then curated quality.
                if ($year !== null) {
                    $q->selectRaw(
                        'artworks.*, (coalesce(quality,0)/50.0'
                        .' + case when depicted_year between ? and ? then 3'
                        .'        when inception_year between ? and ? then 2'
                        .'        when depicted_year is null and inception_year is null then 0'
                        .'        else -2 end) as era_score',
                        [$year - 80, $year + 80, $year - 80, $year + 80],
                    )->orderByRaw('era_score desc, quality desc nulls last');
                } else {
                    $q->orderByRaw('quality desc nulls last, inception_year desc nulls last');
                }

                return $q->first();
            });
        } catch (Throwable) {
            return null;   // corpus unreachable — the caller falls through to Commons
        }

        if (! $artwork || ! $artwork->image_url) {
            return null;
        }

        // Portraits must be cropped from near the TOP or a cover-fit decapitates the sitter.
        $tags = is_array($artwork->tags)
            ? $artwork->tags
            : array_filter(explode(',', trim((string) $artwork->tags, '{}')));

        return [
            // renditionUrl knows the Europeana exception (its CDN ignores ?width=).
            'url' => $artwork->renditionUrl(self::TARGET_WIDTH),
            'credit' => trim(($artwork->creator_name ?: 'Unknown').' — public domain, via '
                .($artwork->source === 'europeana' ? 'Europeana' : 'Wikimedia Commons')),
            'title' => (string) ($artwork->title ?: $query),
            'source' => 'corpus',
            'focus' => PortraitFocus::forTags($tags),
        ];
    }

    /**
     * Best Commons file for a free-text search, taken at the largest sensible rendering.
     *
     * @return array{url:string,credit:string,title:string,source:string}|null
     */
    public function fromCommons(string $query): ?array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(30)
                ->retry(2, 1500)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'format' => 'json',
                    'generator' => 'search',
                    'gsrsearch' => $query,
                    'gsrnamespace' => 6,          // File:
                    'gsrlimit' => 8,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|size|extmetadata',
                    'iiurlwidth' => self::TARGET_WIDTH,
                ]);

            if (! $res->successful()) {
                return null;
            }

            $pages = $res->json('query.pages') ?? [];
            $best = null;
            $bestScore = -1;

            // RELEVANCE FIRST. Commons' search happily returns a Snow White monument for
            // "Frankfurt 1930s street" — technically a Frankfurt file, useless in a lesson. Require
            // the filename to actually echo the subject, and rank on that before size.
            $terms = collect(preg_split('/\s+/', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY))
                ->reject(fn ($t) => mb_strlen($t) < 4 || in_array($t, ['with', 'from', 'view', 'photo', 'the'], true))
                ->values();

            foreach ($pages as $page) {
                $info = $page['imageinfo'][0] ?? null;
                if (! $info) {
                    continue;
                }
                $w = (int) ($info['width'] ?? 0);
                $h = (int) ($info['height'] ?? 0);
                $url = (string) ($info['thumburl'] ?? $info['url'] ?? '');
                if ($url === '' || $w < 600) {
                    continue;   // too small to fill a stage even after upscaling
                }

                $rawTitle = (string) ($page['title'] ?? '');
                // Commons "files" include scanned books, maps as PDFs, and vector diagrams. Those
                // render as a first page or a flat graphic — never as a lesson backdrop.
                if (preg_match('/\.(pdf|djvu|svg|tif|tiff|ogv|webm|gif)$/i', $rawTitle)) {
                    continue;
                }

                $title = mb_strtolower(str_replace(['File:', '_'], ['', ' '], $rawTitle));
                $matched = $terms->filter(fn ($t) => str_contains($title, $t))->count();
                // No shared subject word at all → almost certainly an unrelated file. Skip it.
                if ($terms->isNotEmpty() && $matched === 0) {
                    continue;
                }

                // Prefer big, roughly landscape files — they fill a 16:9 stage without heavy cropping.
                $ratio = $h > 0 ? $w / $h : 1.0;
                $score = ($matched * 5000)                                   // relevance dominates
                    + min($w, 4000)                                          // then resolution
                    + ($ratio >= 1.2 && $ratio <= 2.2 ? 1500 : 0);           // then a usable shape
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $meta = $info['extmetadata'] ?? [];
                    $best = [
                        'url' => $url,
                        'credit' => trim(strip_tags((string) ($meta['Artist']['value'] ?? 'Wikimedia Commons')))
                            .' — '.strip_tags((string) ($meta['LicenseShortName']['value'] ?? 'see Commons')),
                        'title' => str_replace(['File:', '_'], ['', ' '], (string) ($page['title'] ?? $query)),
                        'source' => 'commons',
                        // A taller-than-wide image loses its top to a 16:9 cover crop, and on a
                        // person that top IS the face — so anchor the crop high.
                        'focus' => PortraitFocus::forImage($query.' '.$title, $w, $h),
                    ];
                }
            }

            return $best;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve one named Commons file at a large rendering, with its real attribution.
     *
     * @return array{url:string,credit:string,title:string,source:string}|null
     */
    public function exactCommonsFile(string $fileName): ?array
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            return null;
        }
        if (! str_starts_with($fileName, 'File:')) {
            $fileName = 'File:'.$fileName;
        }

        try {
            $res = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(30)
                ->retry(2, 1500)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'format' => 'json',
                    'titles' => $fileName,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|size|extmetadata',
                    'iiurlwidth' => self::TARGET_WIDTH,
                ]);

            $pages = $res->json('query.pages') ?? [];
            foreach ($pages as $page) {
                if (isset($page['missing'])) {
                    return null;
                }
                $info = $page['imageinfo'][0] ?? null;
                if (! $info) {
                    continue;
                }
                $meta = $info['extmetadata'] ?? [];
                $w = (int) ($info['width'] ?? 0);
                $h = (int) ($info['height'] ?? 0);
                $title = str_replace(['File:', '_'], ['', ' '], $fileName);

                return [
                    'url' => (string) ($info['thumburl'] ?? $info['url'] ?? ''),
                    'credit' => trim(strip_tags((string) ($meta['Artist']['value'] ?? 'Wikimedia Commons')))
                        .' — '.strip_tags((string) ($meta['LicenseShortName']['value'] ?? 'see Commons')),
                    'title' => $title,
                    'source' => 'commons',
                    'focus' => PortraitFocus::forImage($title, $w, $h),
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Download an image and store it on the public disk, returning the stored path (or null).
     * Records nothing itself — the caller decides which scene/gallery it belongs to.
     */
    public function download(string $url, string $path): ?string
    {
        try {
            $res = Http::withHeaders(['User-Agent' => self::UA])->timeout(60)->retry(2, 1500)->get($url);
            if (! $res->successful() || $res->body() === '') {
                return null;
            }
            Storage::disk('public')->put($path, $res->body());

            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    /** Ask a Commons Special:FilePath URL for a large rendering instead of the raw original. */
    private function widen(string $url): string
    {
        if (! str_contains($url, 'Special:FilePath')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'width='.self::TARGET_WIDTH;
    }
}
