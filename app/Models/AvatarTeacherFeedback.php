<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AvatarTeacherFeedback extends Model
{
    protected $table = 'avatar_teacher_feedback';

    protected $fillable = [
        'avatar_id',
        'teacher_id',
        'liked',
        'greeting_audio_path',
    ];

    protected function casts(): array
    {
        return [
            'liked' => 'boolean',
        ];
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Avatar::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function greetingAudioUrl(): ?string
    {
        if (! $this->greeting_audio_path) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->greeting_audio_path)) {
            return null;
        }

        return '/storage/' . ltrim($this->greeting_audio_path, '/');
    }

    public function hasResponded(): bool
    {
        return $this->liked !== null;
    }
}
