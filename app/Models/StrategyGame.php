<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyGame extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'description',
        'subject',
        'topic_keywords',
        'grade_min',
        'grade_max',
        'team_size_min',
        'team_size_max',
        'duration_minutes',
        'instructions',
        'materials',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'topic_keywords'  => 'array',
            'materials'       => 'array',
            'is_active'       => 'boolean',
            'grade_min'       => 'integer',
            'grade_max'       => 'integer',
            'team_size_min'   => 'integer',
            'team_size_max'   => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find the best matching game for a lesson based on keyword overlap.
     * Falls back to the first active game if no keywords match.
     */
    public static function matchForLesson(Lesson $lesson): ?self
    {
        $lessonText = strtolower(
            ($lesson->topic ?? '') . ' ' .
            ($lesson->subject ?? '') . ' ' .
            ($lesson->title ?? '')
        );

        $grade = (int) ($lesson->grade_level ?? 9);

        $games = static::active()
            ->where('grade_min', '<=', $grade)
            ->where('grade_max', '>=', $grade)
            ->get();

        if ($games->isEmpty()) {
            $games = static::active()->get();
        }

        $bestGame  = null;
        $bestScore = -1;

        foreach ($games as $game) {
            $score = 0;
            foreach ($game->topic_keywords as $kw) {
                if (str_contains($lessonText, strtolower($kw))) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestGame  = $game;
            }
        }

        return $bestGame;
    }
}
