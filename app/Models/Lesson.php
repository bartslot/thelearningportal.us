<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LessonStatus;
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
        'title',
        'topic',
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
        'video_path',
        'slideshow_images',
        'duration_seconds',
        'visemes_path',
        'audio_3d_path',
        'blendshapes_path',
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
            'status'               => LessonStatus::class,
            'generation_attempts'  => 'integer',
            'duration_seconds'     => 'integer',
            'slideshow_images'     => 'array',
        ];
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

        return rtrim($this->mediaBaseUrl(), '/') . '/storage/' . ltrim($path, '/');
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

    public function videoUrl(): ?string
    {
        return $this->publicMediaUrl($this->video_path);
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
