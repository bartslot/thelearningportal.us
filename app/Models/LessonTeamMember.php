<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonTeamMember extends Model
{
    protected $fillable = ['team_id', 'student_id', 'student_name'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(LessonTeam::class, 'team_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function getNameAttribute(): string
    {
        return $this->student?->name ?? $this->student_name ?? 'Student';
    }
}
