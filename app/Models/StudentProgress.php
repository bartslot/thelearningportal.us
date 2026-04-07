<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProgress extends Model
{
    protected $fillable = [
        'student_id',
        'lesson_id',
        'watch_seconds',
        'video_completed',
        'answers',
        'score',
        'max_score',
        'quiz_completed_at',
        'completed',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'answers'           => 'array',
            'video_completed'   => 'boolean',
            'completed'         => 'boolean',
            'quiz_completed_at' => 'datetime',
            'completed_at'      => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function scorePercentage(): int
    {
        if (! $this->max_score) return 0;
        return (int) round(($this->score / $this->max_score) * 100);
    }
}
