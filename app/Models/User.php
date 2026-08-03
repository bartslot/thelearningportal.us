<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OnboardingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_guest_demo',
        'locale',
        'teaching_locale',
        'tag',
        'onboarded_at',
        'onboarding_status',
        'onboarding_step',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_guest_demo' => 'boolean',
            'onboarded_at' => 'datetime',
            'onboarding_status' => OnboardingStatus::class,
            'onboarding_step' => 'integer',
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Has this account finished (or skipped) the welcome tour? */
    /**
     * The language this teacher's LESSONS are in (script + narration voice), as opposed to `locale`,
     * which is the language the interface speaks to them in. A Dutch teacher can write English
     * lessons; before these were separated, her English lesson was narrated by a Dutch voice.
     * Null means "same as my interface language", which is true for almost everyone.
     */
    public function teachingLocale(): string
    {
        return $this->teaching_locale ?: ($this->locale ?: 'en');
    }

    public function hasOnboarded(): bool
    {
        return $this->onboardingStatus()->isFinished();
    }

    /** Never null: rows written before the column existed read as Pending. */
    public function onboardingStatus(): OnboardingStatus
    {
        return $this->onboarding_status ?? OnboardingStatus::Pending;
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    /** A throwaway account created by the landing-page demo: a real teacher, fenced in and swept
     *  up after a day. See App\Services\GuestDemoSession. */
    public function isGuestDemo(): bool
    {
        return (bool) $this->is_guest_demo;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Ownership check for teacher-owned records (lessons, classrooms, …).
     * Admin is a superuser role: it manages everything, whoever created it.
     */
    public function canManage(Model $record): bool
    {
        return $this->isAdmin() || (int) ($record->teacher_id ?? 0) === (int) $this->id;
    }

    // ── Teacher relationships ───────────────────────────────────────────────

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'teacher_id');
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'teacher_id');
    }

    // ── Student relationships ───────────────────────────────────────────────

    public function enrolledClassrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_student', 'student_id', 'classroom_id')
            ->withPivot('joined_at');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(StudentProgress::class, 'student_id');
    }
}
