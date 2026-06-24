<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LessonStatus;
use App\Enums\NarrativeFramework;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Lesson extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'teacher_id',
        'avatar_id',
        'region',
        'era',
        'include_game',
        'game_type',
        'quiz_question_count',
        'quiz_timing',
        'strategy_game',
        'strategy_game_id',
        'title',
        'topic',
        'topic_id',
        'focus',
        'title_bg_path',
        'subject',
        'grade_level',
        'tone',
        'details',
        'historical_figure',
        'lesson_code',
        'wikipedia_source',
        'script',
        'status',
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
        });
    }

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'narrative_framework' => NarrativeFramework::class,
            'generation_attempts' => 'integer',
            'duration_seconds' => 'integer',
            'slideshow_images' => 'array',
            'intel_drop_enabled' => 'boolean',
            'intel_drop_at_minutes' => 'integer',
            'outline' => 'array',
            'include_game' => 'boolean',
            'quiz_question_count' => 'integer',
            'team_count' => 'integer',
            'game_split_count' => 'integer',
            'wizard_step' => 'integer',
        ];
    }

    /**
     * Source attribution line for the lesson (A4). Curated catalog topics carry their dataset
     * licences; everything is grounded in Wikipedia. Returns null for legacy/uploaded sources.
     */
    public function sourceAttribution(): ?string
    {
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

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('order');
    }

    /** Ordered lesson modules (Epic K) — the modular replacement for scenes. */
    public function modules(): HasMany
    {
        return $this->hasMany(LessonModule::class)->orderBy('order');
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

    public function cardImageUrl(): ?string
    {
        // 1. First scene's generated image (skybox preferred, then scene image)
        if ($this->relationLoaded('firstScene') && $this->firstScene) {
            $scene = $this->firstScene;
            $url = $this->publicMediaUrl($scene->skybox_image_path)
                  ?? $this->publicMediaUrl($scene->image_path);
            if ($url) {
                return $url;
            }
        }

        // 2. Source hero image
        if ($this->relationLoaded('source') && $this->source?->hero_image_path) {
            $url = $this->publicMediaUrl($this->source->hero_image_path);
            if ($url) {
                return $url;
            }
        }

        // 3. Portrait fallback
        if ($this->portrait_path) {
            return $this->publicMediaUrl($this->portrait_path);
        }

        // 4. Wikipedia lead image (title-screen background) — last resort so every
        //    catalog lesson shows a topical cover instead of the generic glyph.
        if ($this->title_bg_path) {
            return $this->publicMediaUrl($this->title_bg_path);
        }

        return null;
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
