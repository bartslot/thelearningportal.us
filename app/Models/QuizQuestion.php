<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    protected $fillable = [
        'lesson_id',
        'order',
        'question',
        'options',
        'correct_index',
        'explanation',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'options'       => 'array',
            'correct_index' => 'integer',
            'points'        => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function isCorrect(int $answerIndex): bool
    {
        return $answerIndex === $this->correct_index;
    }

    public function correctAnswer(): string
    {
        return $this->options[$this->correct_index] ?? '';
    }
}
