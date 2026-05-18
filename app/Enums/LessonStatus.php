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

    case Draft             = 'draft';
    case SourceReady       = 'source_ready';
    case Outlining         = 'outlining';
    case ScenesGenerating  = 'scenes_generating';
    case ScenesReady       = 'scenes_ready';
    case Configuring       = 'configuring';
    case Previewable       = 'previewable';

    public function label(): string
    {
        return match($this) {
            self::Pending           => 'Waiting to generate',
            self::Generating        => 'Generating...',
            self::Ready             => 'Ready (draft)',
            self::Failed            => 'Generation failed',
            self::Published         => 'Published',
            self::Draft             => 'Draft',
            self::SourceReady       => 'Source ready',
            self::Outlining         => 'Outlining...',
            self::ScenesGenerating  => 'Generating scenes...',
            self::ScenesReady       => 'Scenes ready',
            self::Configuring       => 'Configuring',
            self::Previewable       => 'Previewable',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending           => 'yellow',
            self::Generating        => 'blue',
            self::Ready             => 'green',
            self::Failed            => 'red',
            self::Published         => 'indigo',
            self::Draft             => 'gray',
            self::SourceReady       => 'cyan',
            self::Outlining         => 'blue',
            self::ScenesGenerating  => 'blue',
            self::ScenesReady       => 'green',
            self::Configuring       => 'amber',
            self::Previewable       => 'green',
        };
    }
}
