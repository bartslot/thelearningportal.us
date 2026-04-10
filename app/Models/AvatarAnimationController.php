<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvatarAnimationController extends Model
{
    protected $fillable = ['avatar_id', 'controller'];

    protected $casts = ['controller' => 'array'];

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Avatar::class);
    }

    public static function defaultControllerData(): array
    {
        return [
            'idle'       => [],
            'presenting' => [],
            'greeting'   => [],
        ];
    }
}
