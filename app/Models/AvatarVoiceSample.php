<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AvatarVoiceSample extends Model
{
    protected $fillable = [
        'avatar_id',
        'phrase',
        'voice_id',
        'voice_speed',
        'audio_path',
        'audio_extension',
        'settings_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'voice_speed'       => 'float',
            'settings_snapshot' => 'array',
        ];
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Avatar::class);
    }

    public function audioUrl(): ?string
    {
        if (! $this->audio_path) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->audio_path)) {
            return null;
        }

        return '/storage/' . ltrim($this->audio_path, '/');
    }

    public function label(): string
    {
        $snapshot = $this->settings_snapshot ?? [];

        // Prefer the stored voice label (set at sample generation time)
        if (! empty($snapshot['voice_label'])) {
            return $snapshot['voice_label'];
        }

        // Fall back to voice_id
        return $this->voice_id ?? 'Unknown';
    }
}
