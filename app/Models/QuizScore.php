<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizScore extends Model
{
    protected $fillable = [
        'lesson_id', 'nickname', 'classroom_member_id', 'score',
        'correct', 'total', 'integrity', 'source',
    ];

    protected function casts(): array
    {
        return ['score' => 'integer', 'correct' => 'integer', 'total' => 'integer', 'integrity' => 'array'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ClassroomMember::class, 'classroom_member_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class)->orderBy('question_order');
    }
}
