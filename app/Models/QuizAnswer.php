<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAnswer extends Model
{
    protected $fillable = [
        'quiz_score_id', 'quiz_question_id', 'question_order',
        'question_text', 'chosen_text', 'correct_text',
        'was_correct', 'response_ms', 'asks_ahead',
    ];

    protected function casts(): array
    {
        return [
            'question_order' => 'integer',
            'was_correct' => 'boolean',
            'response_ms' => 'integer',
            'asks_ahead' => 'boolean',
        ];
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(QuizScore::class, 'quiz_score_id');
    }
}
