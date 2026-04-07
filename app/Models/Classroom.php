<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Classroom extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'teacher_id',
        'name',
        'join_code',
        'grade_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Classroom $classroom) {
            if (empty($classroom->join_code)) {
                $classroom->join_code = strtoupper(Str::random(6));
            }
        });
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_student', 'classroom_id', 'student_id')
                    ->withPivot('joined_at');
    }

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'classroom_lessons')
                    ->withPivot('assigned_at', 'due_at');
    }
}
