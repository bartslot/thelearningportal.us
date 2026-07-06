<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorySource extends Model
{
    protected $fillable = [
        'story_id', 'origin', 'title', 'author', 'license', 'url', 'excerpt', 'order',
    ];

    protected function casts(): array
    {
        return ['order' => 'integer'];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
