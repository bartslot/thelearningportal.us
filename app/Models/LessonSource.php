<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonSource extends Model
{
    protected $fillable = [
        'lesson_id', 'kind', 'original_filename',
        'file_path', 'extracted_text', 'wikipedia_topic', 'source_url', 'hero_image_path', 'hero_image_url',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
