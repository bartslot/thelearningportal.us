<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scene extends Model
{
    protected $fillable = [
        'lesson_id', 'order', 'kind',
        'year', 'location', 'script_segment',
        'image_prompt', 'image_path', 'image_style',
        'skybox_blur', 'skybox_opacity', 'background_color', 'scene_view',
        'animation_clip_id',
        'audio_path', 'audio_alignment', 'audio_script_hash',
        'duration_seconds', 'game_segment_index',
        'status', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'order'              => 'integer',
            'audio_alignment'    => 'array',
            'duration_seconds'   => 'integer',
            'game_segment_index' => 'integer',
            'skybox_blur'        => 'float',
            'skybox_opacity'     => 'float',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function animationClip(): BelongsTo
    {
        return $this->belongsTo(AnimationClip::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function hasFreshAudio(): bool
    {
        return $this->audio_path !== null
            && $this->audio_script_hash === sha1((string) $this->script_segment);
    }
}
