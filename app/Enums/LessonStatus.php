<?php

declare(strict_types=1);

namespace App\Enums;

enum LessonStatus: string
{
    case Pending    = 'pending';
    case Generating = 'generating';
    case Ready      = 'ready';
    case Failed     = 'failed';
    case Published  = 'published';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Waiting to generate',
            self::Generating => 'Generating...',
            self::Ready      => 'Ready (draft)',
            self::Failed     => 'Generation failed',
            self::Published  => 'Published',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending    => 'yellow',
            self::Generating => 'blue',
            self::Ready      => 'green',
            self::Failed     => 'red',
            self::Published  => 'indigo',
        };
    }
}
