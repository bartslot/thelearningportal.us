<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StoryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A curated catalog story: a vetted historical narrative with learning objectives,
 * key facts, misconceptions, and real narrative source excerpts. Teachers pick from
 * published stories instead of free-texting topics — this is what "limit the amount
 * of possible stories to tell" means in the data model.
 */
class Story extends Model
{
    use SoftDeletes;

    /** In-memory default so a freshly created Story has a status before refresh(). */
    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'slug', 'title', 'subtitle',
        'topic_id', 'protagonist_qid', 'protagonist_name', 'narrative_framework',
        'era_start', 'era_end', 'region', 'grade_band', 'locale',
        'learning_objectives', 'key_facts', 'misconceptions', 'quiz_blueprint',
        'assets',
        'status', 'created_by', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoryStatus::class,
            'era_start' => 'integer',
            'era_end' => 'integer',
            'learning_objectives' => 'array',
            'key_facts' => 'array',
            'misconceptions' => 'array',
            'quiz_blueprint' => 'array',
            'assets' => 'array',
        ];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(StorySource::class)->orderBy('order');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePublished($query)
    {
        return $query->where('status', StoryStatus::Published);
    }

    /** Guarded state transition — throws on an illegal jump (e.g. draft → published). */
    public function transitionTo(StoryStatus $target, ?int $reviewerId = null): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new \DomainException("Story #{$this->id}: illegal transition {$this->status->value} → {$target->value}.");
        }

        $this->update(array_filter([
            'status' => $target,
            'reviewed_by' => $reviewerId,
        ], fn ($value) => $value !== null));
    }

    /** Concatenated narrative excerpts — the authoritative source text for generation. */
    public function combinedSourceText(): string
    {
        return $this->sources
            ->map(fn (StorySource $source) => trim($source->excerpt))
            ->filter()
            ->implode("\n\n");
    }

    /** Attribution lines for the lesson player (license compliance). */
    public function attributionLine(): ?string
    {
        $parts = $this->sources
            ->map(function (StorySource $source): string {
                $author = $source->author ? " — {$source->author}" : '';
                $license = $source->license ? " ({$source->license})" : '';

                return $source->title.$author.$license;
            })
            ->unique()
            ->take(3);

        return $parts->isEmpty() ? null : 'Sources: '.$parts->implode(' · ');
    }
}
