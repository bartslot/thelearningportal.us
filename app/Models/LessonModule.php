<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModuleType;
use App\Lessons\Modules\LessonModuleType;
use App\Lessons\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered, typed building block of a lesson (Epic K). Replaces "scenes".
 * Per-type content lives in `config` (jsonb); `content_ref` optionally points at a heavy
 * related row (e.g. a strategy_games id).
 */
class LessonModule extends Model
{
    protected $fillable = [
        'lesson_id',
        'type',
        'title',
        'order',
        'config',
        'content_ref',
        'estimated_duration_seconds',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => ModuleType::class,
            'order' => 'integer',
            'config' => 'array',
            'estimated_duration_seconds' => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order');
    }

    /** The behaviour implementation for this module's type (editor/slide rendering, defaults). */
    public function implementation(): LessonModuleType
    {
        return ModuleRegistry::for($this->type);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
