<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NarratorAnimationController extends Model
{
    protected $table = 'avatar_animation_controllers';

    protected $fillable = ['avatar_id', 'controller'];

    protected $casts = ['controller' => 'array'];

    public function narrator(): BelongsTo
    {
        return $this->belongsTo(Narrator::class, 'avatar_id');
    }

    public static function defaultControllerData(): array
    {
        return [
            'idle'       => [],
            'expression' => [],
            'dance'      => [],
        ];
    }
}
