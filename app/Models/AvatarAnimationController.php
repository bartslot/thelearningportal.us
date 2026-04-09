<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvatarAnimationController extends Model
{
    protected $fillable = ['avatar_id', 'controller'];

    protected $casts = ['controller' => 'array'];

    protected static function booted(): void
    {
        // Cascade soft-deletes: when an Avatar is soft-deleted, remove its controller.
        Avatar::deleting(function (Avatar $avatar): void {
            static::where('avatar_id', $avatar->id)->delete();
        });
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Avatar::class);
    }

    /** Default controller structure — all slots empty, ready for clip assignment. */
    public static function defaultControllerData(): array
    {
        return [
            'version' => 1,
            'slots'   => [
                'idle'            => ['mode' => 'sequential', 'clips' => []],
                'walk'            => ['mode' => 'random',     'clips' => []],
                'gesture'         => ['mode' => 'random',     'clips' => []],
                'emotion_excited' => ['mode' => 'random',     'clips' => []],
                'emotion_serious' => ['mode' => 'random',     'clips' => []],
                'emotion_whisper' => ['mode' => 'random',     'clips' => []],
            ],
            'transitions' => [
                'gesture_fade_out' => 0.3,
                'emotion_fade_out' => 0.5,
                'walk_fade_in'     => 0.2,
                'idle_fade_in'     => 0.3,
            ],
        ];
    }
}
