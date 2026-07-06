<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story catalog review state machine: draft → reviewed → published.
 * Only published stories are visible to teachers in the wizard.
 */
enum StoryStatus: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Reviewed => 'Reviewed',
            self::Published => 'Published',
        };
    }

    /** Forward-only transitions (plus unpublish back to reviewed for corrections). */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => $target === self::Reviewed,
            self::Reviewed => in_array($target, [self::Published, self::Draft], true),
            self::Published => $target === self::Reviewed,
        };
    }
}
