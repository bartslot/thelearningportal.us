<?php

declare(strict_types=1);

namespace App\Enums;

enum LessonStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Published = 'published';

    case Draft = 'draft';
    case SourceReady = 'source_ready';
    case FetchingSources = 'fetching_sources';
    case Outlining = 'outlining';
    case ScenesGenerating = 'scenes_generating';
    case ScenesReady = 'scenes_ready';
    case Configuring = 'configuring';
    case Previewable = 'previewable';

    /**
     * Is the generation pipeline still working on this lesson? While it is, the Generate screen and
     * its progress bar are the only useful view — a preview would be half-built.
     */
    public function isGenerating(): bool
    {
        return in_array($this, [
            self::Generating,
            self::ScenesGenerating,
            self::Outlining,
            self::FetchingSources,
        ], true);
    }

    /**
     * Human label for the status chip. Translated: these strings sit on the teacher's dashboard and
     * lesson cards, so a Dutch or German teacher must not meet an English "Previewable" there.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting to generate'),
            self::Generating => __('Generating...'),
            self::Ready => __('Ready (draft)'),
            self::Failed => __('Generation failed'),
            self::Published => __('Published'),
            self::Draft => __('Draft'),
            self::SourceReady => __('Source ready'),
            self::FetchingSources => __('Fetching sources…'),
            self::Outlining => __('Outlining...'),
            self::ScenesGenerating => __('Generating scenes...'),
            self::ScenesReady => __('Scenes ready'),
            self::Configuring => __('Configuring'),
            self::Previewable => __('Previewable'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Generating => 'blue',
            self::Ready => 'green',
            self::Failed => 'red',
            self::Published => 'indigo',
            self::Draft => 'gray',
            self::SourceReady => 'cyan',
            self::FetchingSources => 'cyan',
            self::Outlining => 'blue',
            self::ScenesGenerating => 'blue',
            self::ScenesReady => 'green',
            self::Configuring => 'amber',
            self::Previewable => 'green',
        };
    }
}
