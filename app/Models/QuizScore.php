<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizScore extends Model
{
    protected $fillable = ['lesson_id', 'nickname', 'score', 'correct', 'total'];

    protected function casts(): array
    {
        return ['score' => 'integer', 'correct' => 'integer', 'total' => 'integer'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
