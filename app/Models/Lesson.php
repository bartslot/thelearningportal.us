<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LessonStatus;
use App\Enums\NarrativeFramework;
use App\Models\Concerns\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Lesson extends Model
{
    use BelongsToTeacher, HasFactory, SoftDeletes;

    protected $fillable = [
        'teacher_id',
        'avatar_id',
        'region',
        'era',
        'include_game',
        'game_type',
        'game_config',
        'game_pack_path',
        'quiz_question_count',
        'quiz_timing',
        'map_style',
        'map_relief',
        'strategy_game',
        'strategy_game_id',
        'title',
        'language',
        'narration_edit_characters',
        'topic',
        'topic_id',
        'story_id',
        'canon_theme',
        'canon_intent',
        'focus',
        'focus_tags',
        'title_bg_path',
        'poster_image',
        'subject',
        'grade_level',
        'tone',
        'details',
        'historical_figure',
        'lesson_code',
        'wikipedia_source',
        'script',
        'status',
        'scheduled_publish_at',
        'error_message',
        'generation_attempts',
        'portrait_path',
        'audio_path',
        'slideshow_images',
        'intel_drop_enabled',
        'intel_drop_at_minutes',
        'intel_drop_script',
        'intel_drop_audio_path',
        'duration_seconds',
        'visemes_path',
        'audio_3d_path',
        'blendshapes_path',
        'image_style',
        'source_mode',
        'team_count',
        'game_split_count',
        'outline',
        'wizard_step',
        'background_music',
        'subtitles',
        'narrative_framework',
        'protagonist_qid',
        'protagonist_name',
    ];

    protected static function booted(): void
    {
        static::creating(function (Lesson $lesson): void {
            if (empty($lesson->lesson_code)) {
                do {
                    $code = strtoupper(Str::random(6));
                } while (static::where('lesson_code', $code)->exists());
                $lesson->lesson_code = $code;
            }

            if (empty($lesson->canon_theme) && $lesson->teacher_id) {
                $teacher = User::query()->find($lesson->teacher_id);
                $catalog = app(\App\Services\CanonThemeCatalog::class);

                if ($catalog->isDutchTeacher($teacher)) {
                    $lesson->canon_theme = $catalog->suggest(
                        $lesson->topic,
                        $lesson->details,
                        $lesson->focus,
                    );
                }
            }
        });

        // Keep the cached public pages fresh: any lesson save/delete (publish, status change,
        // edit, removal) drops both the landing-page list (routes/web.php home route) and this
        // lesson's player payload (LessonPlayerController), so changes show on the next request
        // instead of up to 10–30 min later.
        $forgetCaches = static function (Lesson $lesson): void {
            Cache::forget('home.playable_lessons');
            \App\Support\DemoLesson::forget();
            if (! empty($lesson->lesson_code)) {
                Cache::forget('lesson.player.'.strtoupper($lesson->lesson_code));
            }
        };
        static::saved($forgetCaches);
        static::deleted($forgetCaches);

        // A cover is rendered FROM the poster override, so changing the poster makes the existing
        // cover a picture of the wrong thing — and because the cover outranks the override on the
        // card, the teacher would see their change ignored. Drop it and let the card fall back to
        // the new poster until app:generate-lesson-covers renders the replacement.
        static::updating(function (Lesson $lesson): void {
            if (! $lesson->isDirty('poster_image')) {
                return;
            }

            $lesson->cover_url = null;
            Storage::disk('public')->delete("lessons/{$lesson->id}/cover.webp");
        });
    }

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'scheduled_publish_at' => 'datetime',
            'narrative_framework' => NarrativeFramework::class,
            'generation_attempts' => 'integer',
            'duration_seconds' => 'integer',
            'slideshow_images' => 'array',
            'intel_drop_enabled' => 'boolean',
            'intel_drop_at_minutes' => 'integer',
            'outline' => 'array',
            'game_config' => 'array',
            'include_game' => 'boolean',
            'quiz_question_count' => 'integer',
            'map_relief' => 'float',
            'team_count' => 'integer',
            'game_split_count' => 'integer',
            'wizard_step' => 'integer',
            'focus_tags' => 'array',
            'subtitles' => 'boolean',
            'background_music' => 'boolean',
        ];
    }

    /**
     * Source attribution line for the lesson (A4). Curated catalog topics carry their dataset
     * licences; everything is grounded in Wikipedia. Returns null for legacy/uploaded sources.
     */
    public function sourceAttribution(): ?string
    {
        // Catalog stories cite their curated sources (Gutenberg book, author, license).
        if ($this->story_id && ($line = $this->story?->attributionLine())) {
            return $line;
        }

        if (str_starts_with((string) $this->topic_id, 'figure:')) {
            return 'Sources: Wikidata (CC0) · Wikipedia (CC BY-SA)';
        }
        if (str_starts_with((string) $this->topic_id, 'polity:')) {
            return 'Sources: Cliopatria / Seshat (CC BY 4.0) · Wikipedia (CC BY-SA)';
        }

        return null;
    }

    protected function normalizeHistoricalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_ireplace(
            ['Iran/Irak', 'Iran Irak', 'Irak'],
            ['Iran/Iraq', 'Iran Iraq', 'Iraq'],
            $value
        );
    }

    public function getTitleAttribute(?string $value): ?string
    {
        return $this->normalizeHistoricalText($value);
    }

    public function getTopicAttribute(?string $value): ?string
    {
        return $this->normalizeHistoricalText($value);
    }

    // ── Relationships ───────────────────────────────────────────────────────

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Avatar::class);
    }

    /** Curated catalog story this lesson is grounded in (null for free-topic lessons). */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function strategyGame(): BelongsTo
    {
        return $this->belongsTo(\App\Models\StrategyGame::class);
    }

    public function teams(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\LessonTeam::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function quizQuestions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('order');
    }

    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_lessons')
            ->withPivot('assigned_at', 'due_at');
    }

    public function studentProgress(): HasMany
    {
        return $this->hasMany(StudentProgress::class);
    }

    public function quizScores(): HasMany
    {
        return $this->hasMany(QuizScore::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('order');
    }

    /** Ordered lesson modules (Epic K) — the modular replacement for scenes. */
    public function modules(): HasMany
    {
        return $this->hasMany(LessonModule::class)->orderBy('order');
    }

    /**
     * Which wizard step a lesson card on the dashboard should open.
     *
     * Teachers open an existing lesson to WATCH it far more often than to re-edit its settings, so a
     * lesson that has scenes goes straight to Preview (step 5) instead of resuming wherever the
     * wizard was last left. Two cases still resume instead, because a preview would be useless:
     * a lesson mid-generation (its progress bar is the point) and one with no scenes yet.
     *
     * Returns null for "resume at wizard_step", which is what LessonWizard::mount() does with no
     * ?step= in the URL.
     *
     * Reads the eager-loaded firstScene relation (both dashboard controllers load it), so this
     * costs no extra query per card.
     */
    public function cardEntryStep(): ?int
    {
        if ($this->status->isGenerating() || $this->status === LessonStatus::Failed) {
            return null;
        }

        return $this->firstScene !== null ? 5 : null;
    }

    public function firstScene(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Scene::class)->oldestOfMany('order');
    }

    public function source(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LessonSource::class)->latestOfMany();
    }

    public function startGenerationPipeline(): void
    {
        if (! $this->source()->exists()) {
            throw new \RuntimeException('Cannot start pipeline: no LessonSource attached.');
        }

        $this->update(['status' => LessonStatus::SourceReady]);
        \App\Jobs\BuildLessonOutline::dispatch($this->id);
    }

    /**
     * Persist a new module order from a list of module ids. Uses a two-phase shift to avoid the
     * (lesson_id, order) unique clash (same pattern as Scene::insertDefaultMapBlock). Ids not
     * belonging to this lesson are ignored.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderModules(array $orderedIds): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                $this->modules()->whereKey($id)->update(['order' => -1 * ($index + 1)]);
            }
            foreach ($orderedIds as $index => $id) {
                $this->modules()->whereKey($id)->update(['order' => $index]);
            }
        });
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopePublished($query)
    {
        return $query->where('status', LessonStatus::Published);
    }

    public function scopeReady($query)
    {
        return $query->whereIn('status', [LessonStatus::Ready, LessonStatus::Published]);
    }

    // ── Media URL helpers ────────────────────────────────────────────────────

    private function publicMediaUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $publicDisk = Storage::disk('public');

        if (! $publicDisk->exists($path) && Storage::disk('local')->exists($path)) {
            $publicDisk->put($path, Storage::disk('local')->get($path));
        }

        if (! $publicDisk->exists($path)) {
            return null;
        }

        return rtrim($this->mediaBaseUrl(), '/').'/storage/'.ltrim($path, '/');
    }

    private function mediaBaseUrl(): string
    {
        $fallback = (string) (config('app.url') ?: url('/'));

        if (app()->runningInConsole()) {
            return rtrim($fallback, '/');
        }

        try {
            return rtrim(request()->getSchemeAndHttpHost(), '/');
        } catch (\Throwable) {
            return rtrim($fallback, '/');
        }
    }

    private function publicMediaMimeType(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $publicDisk = Storage::disk('public');

        if ($publicDisk->exists($path)) {
            try {
                $mime = $publicDisk->mimeType($path);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            } catch (\Throwable) {
                // Fall back below.
            }
        }

        if (Storage::disk('local')->exists($path)) {
            $absolutePath = Storage::disk('local')->path($path);
            $mime = @mime_content_type($absolutePath);

            return is_string($mime) && $mime !== '' ? $mime : null;
        }

        return null;
    }

    private function mediaExists(?string $path): bool
    {
        return $path !== null
            && (
                Storage::disk('public')->exists($path)
                || Storage::disk('local')->exists($path)
            );
    }

    public function visemesUrl(): ?string
    {
        return $this->publicMediaUrl($this->visemes_path);
    }

    public function audioUrl(): ?string
    {
        return $this->publicMediaUrl($this->audio_path);
    }

    public function audioMimeType(): ?string
    {
        return $this->publicMediaMimeType($this->audio_path);
    }

    public function portraitUrl(): string
    {
        return $this->publicMediaUrl($this->portrait_path) ?? asset('assets/professor.webp');
    }

    /**
     * Best available card/cover image for the lesson, in priority order:
     *   1. worldhistory.org hero image (colorful, editorial quality)
     *   2. Avatar portrait
     *   3. Generic placeholder
     */
    /** Flag of the lesson's territory (polity), if the catalog flag was downloaded. */
    public function territoryFlagUrl(): ?string
    {
        if (! str_starts_with((string) $this->topic_id, 'polity:')) {
            return null;
        }
        $qid = substr((string) $this->topic_id, strlen('polity:'));

        return file_exists(public_path("flags/{$qid}.png")) ? asset("flags/{$qid}.png") : null;
    }

    /** Wikipedia lead image used as the lesson title-screen background (catalog lessons). */
    public function titleBgUrl(): ?string
    {
        return $this->title_bg_path ? $this->publicMediaUrl($this->title_bg_path) : null;
    }

    /**
     * Scenic portrait of the lesson's protagonist (Wikidata P18), resolved from the read-only
     * corpus by QID. People-topic lessons should show the person, never a flag. Cached per QID
     * (the corpus is static) so per-card resolution on a list page stays O(1) and survives a
     * dropped pooler connection without caching the transient failure.
     */
    public function protagonistImageUrl(): ?string
    {
        $url = $this->protagonistImageUrlFull();
        if ($url === null) {
            return null;
        }

        // Bound the raw Wikimedia original so even the pre-cover fallback <img> never pulls a
        // multi-MB file. Cover generation uses the unbounded full-size original instead.
        return $this->boundedWikimediaImageUrl($url, 600);
    }

    /**
     * Full-size protagonist portrait URL (corpus P18) with no thumbnail bound — the best source
     * for a high-quality cover crop. Cached per QID for a week (the corpus is static); a transient
     * corpus failure is never cached so the next request retries.
     */
    public function protagonistImageUrlFull(): ?string
    {
        if (! $this->protagonist_qid) {
            return null;
        }

        $key = "figure_image:{$this->protagonist_qid}";
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached ?: null; // '' is the cached "known to have no portrait" sentinel
        }

        try {
            $url = \App\Models\Corpus\Figure::on('pgsql_corpus')
                ->where('qid', $this->protagonist_qid)
                ->value('image_url');
        } catch (\Throwable $e) {
            return null; // corpus unreachable — don't cache, retry next request
        }

        Cache::put($key, $url ?? '', now()->addWeek());

        return $url ?: null;
    }

    /**
     * Append a bounded thumbnail width to a Wikimedia Special:FilePath URL (preserving any existing
     * query string) so an <img> never streams a multi-MB original. Non-Wikimedia URLs are returned
     * unchanged.
     */
    private function boundedWikimediaImageUrl(string $url, int $width): string
    {
        if (! str_contains($url, 'Special:FilePath') || str_contains($url, 'width=')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'width='.$width;
    }

    /**
     * Public URL for the pre-rendered local WebP cover, or null if it hasn't been generated yet.
     * Built exactly like publicMediaUrl() so it matches every other media URL on the model.
     */
    public function coverImageUrl(): ?string
    {
        // The CDN copy wins: same picture, served as a small WebP from Cloudinary instead of
        // off our own box. Written by app:generate-lesson-covers; null until it has run with
        // Cloudinary configured, and the local file below carries the page until then.
        if ($cdn = trim((string) ($this->cover_url ?? ''))) {
            return $cdn;
        }

        $path = "lessons/{$this->id}/cover.webp";

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return rtrim($this->mediaBaseUrl(), '/').'/storage/'.ltrim($path, '/');
    }

    /**
     * Public wrapper around the private publicMediaUrl() so the cover-generation command can
     * resolve a storage-path source image without duplicating URL-building logic.
     */
    public function coverSourceMediaUrl(?string $path): ?string
    {
        return $this->publicMediaUrl($path);
    }

    /**
     * Best SCENIC cover for the lesson, in priority order. Never a flag or a bare map: the
     * Wikipedia title image (which is a flag/map for some empires) sits below the protagonist
     * portrait, the generated scene, and the editorial hero — so person-led empire lessons show
     * the person. The pre-filtered slideshow art is a safety net before the avatar headshot.
     */
    public function cardImageUrl(): ?string
    {
        // The pre-rendered WebP cover (small, cropped, CDN-hosted) wins outright — including over
        // a teacher's poster override, because the cover is RENDERED FROM whatever the chain below
        // picked. The override decides WHICH picture the card shows; the cover decides how it is
        // delivered. Choosing the override here instead meant an overridden poster shipped as a
        // multi-megabyte original for ever, which is most of them.
        if ($url = $this->coverImageUrl()) {
            return $url;
        }

        return $this->cardImageSourceUrl();
    }

    /**
     * The card image chain WITHOUT the pre-rendered cover — i.e. the original artwork a cover is
     * rendered from, and what a card shows until one exists.
     *
     * Public because app:generate-lesson-covers needs exactly this: it used to keep its own copy
     * of the chain, which silently drifted (it was missing the slideshow art and avatar portrait
     * steps), so lessons whose only image came from those reported "no source image available"
     * and their cards went on serving multi-megabyte originals for ever.
     */
    public function cardImageSourceUrl(): ?string
    {
        // 0. A teacher-chosen poster always wins — it's the explicit override.
        if ($url = $this->posterOverrideUrl()) {
            return $url;
        }

        // 1. People: scenic portrait/painting of the protagonist (corpus P18).
        if ($url = $this->protagonistImageUrl()) {
            return $url;
        }

        // 2. First scene's generated panorama (skybox preferred, then the base scene image).
        if ($this->relationLoaded('firstScene') && $this->firstScene) {
            $url = $this->publicMediaUrl($this->firstScene->skybox_image_path)
                  ?? $this->publicMediaUrl($this->firstScene->image_path);
            if ($url) {
                return $url;
            }
        }

        // 3. Editorial hero image (worldhistory.org), when the source provided one.
        if ($this->relationLoaded('source') && $this->source?->hero_image_path) {
            if ($url = $this->publicMediaUrl($this->source->hero_image_path)) {
                return $url;
            }
        }

        // 4. Wikipedia title-screen image — scenic for most topics (a flag/map only for some
        //    empires, which are person-led and already covered by step 1).
        if ($this->title_bg_path) {
            if ($url = $this->publicMediaUrl($this->title_bg_path)) {
                return $url;
            }
        }

        // 5. Curated slideshow art (Europeana/Wikimedia, pre-filtered to drop flags/maps/icons).
        foreach ($this->slideshowImages() as $img) {
            if (! empty($img['url'])) {
                return $img['url'];
            }
        }

        // 6. Avatar portrait — last resort headshot.
        if ($this->portrait_path) {
            return $this->publicMediaUrl($this->portrait_path);
        }

        return null;
    }

    /** The teacher's explicit poster override as a usable URL, or null when unset. */
    public function posterOverrideUrl(): ?string
    {
        $poster = trim((string) ($this->poster_image ?? ''));
        if ($poster === '') {
            return null;
        }

        // Full URLs (Cloudinary/Commons) and root-relative paths pass through; a bare storage
        // path is resolved on the public disk like every other stored image.
        return preg_match('#^(https?:)?//#i', $poster) || str_starts_with($poster, '/')
            ? $poster
            : $this->publicMediaUrl($poster);
    }

    /**
     * The lesson's poster — NEVER empty. The teacher's override wins; otherwise the first image
     * already loaded in the lesson (auto-pick), then the general card fallback chain, and finally
     * the narrator portrait (which itself has a built-in placeholder).
     */
    public function posterUrl(): string
    {
        return $this->posterOverrideUrl()
            ?? ($this->posterCandidates()[0]['url'] ?? null)
            ?? $this->cardImageUrl()
            ?? $this->portraitUrl();
    }

    /**
     * Every image already loaded in this lesson, as picker candidates. Unions the lesson-level
     * imagery with each scene's imagery (generated art, skyboxes, and voyage/gallery config images),
     * de-duplicated, so a teacher can pick any of them as the poster.
     *
     * @return array<int,array{url:string,label:string}>
     */
    /**
     * This lesson's own artwork, packed into one sprite sheet for the hero timewarp.
     *
     * Built by `lessons:build-warp-atlas`, which puts the cell count in the filename so the
     * browser can slice the sheet without a database column. Null when no sheet has been built,
     * in which case the hero simply flies through the generic history cards.
     *
     * @return array{url: string, cells: int}|null
     */
    public function warpAtlas(): ?array
    {
        foreach (Storage::disk('public')->files("lessons/{$this->id}") as $file) {
            if (preg_match('/warp-atlas-(\d+)\.webp$/', $file, $match)) {
                return ['url' => Storage::disk('public')->url($file), 'cells' => (int) $match[1]];
            }
        }

        return null;
    }

    public function posterCandidates(): array
    {
        $out = [];
        $push = function (?string $url, string $label) use (&$out): void {
            $url = trim((string) $url);
            if ($url !== '' && ! isset($out[$url])) {
                $out[$url] = ['url' => $url, 'label' => $label];
            }
        };

        // Lesson-level imagery.
        if ($u = $this->publicMediaUrl($this->title_bg_path)) {
            $push($u, 'Title background');
        }
        foreach ($this->slideshowImages() as $img) {
            $push($img['url'] ?? null, $img['title'] ?? 'Slideshow image');
        }

        // Per-scene imagery, in order — includes voyage/gallery config images (URLs already absolute).
        foreach ($this->scenes()->ordered()->get() as $scene) {
            $push($this->publicMediaUrl($scene->skybox_image_path), 'Scene panorama');
            $push($this->publicMediaUrl($scene->image_path), 'Scene image');
            $cfg = $scene->config ?? [];
            foreach (($cfg['stop_images'] ?? []) as $u) {
                $push(is_string($u) ? $u : ($u['url'] ?? null), 'Landfall image');
            }
            foreach (($cfg['images'] ?? []) as $u) {
                $push(is_array($u) ? ($u['url'] ?? null) : $u, 'Gallery image');
            }
            foreach ((($cfg['gallery'] ?? [])['images'] ?? []) as $u) {
                $push(is_array($u) ? ($u['url'] ?? null) : $u, 'Gallery image');
            }
        }

        return array_values($out);
    }

    /**
     * Return slideshow images stored from Europeana/Wikimedia.
     * Each entry: {url, thumb, title, attribution, source, link}
     *
     * @return array<int, array{url:string, thumb:string, title:string, attribution:string, source:string, link:string|null}>
     */
    public function slideshowImages(): array
    {
        return is_array($this->slideshow_images) ? $this->slideshow_images : [];
    }

    /**
     * Whether slideshow images have been fetched for this lesson.
     */
    public function hasSlideshowImages(): bool
    {
        return ! empty($this->slideshow_images);
    }

    // ── Status helpers ───────────────────────────────────────────────────────

    public function isReady(): bool
    {
        return in_array($this->status, [LessonStatus::Ready, LessonStatus::Published])
            && $this->hasGeneratedAssets();
    }

    public function isGenerating(): bool
    {
        return $this->status === LessonStatus::Generating;
    }

    public function canRetry(): bool
    {
        return $this->status === LessonStatus::Failed
            && $this->generation_attempts < config('lessons.max_generation_attempts', 3);
    }

    public function hasGeneratedAssets(): bool
    {
        return $this->script !== null
            && $this->quizQuestions()->exists()
            && $this->mediaExists($this->audio_path);
    }
}
