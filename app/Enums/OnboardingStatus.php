<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingStatus: string
{
    /** Brand-new account: the tour has never opened. */
    case Pending = 'pending';

    /** Opened but not finished — the account resumes at users.onboarding_step. */
    case Active = 'active';

    /** Closed early. Not shown again on its own; the help centre can restart it. */
    case Dismissed = 'dismissed';

    /** Walked through to the end. */
    case Completed = 'completed';

    /** Should the tour open by itself on the dashboard? */
    public function opensAutomatically(): bool
    {
        return in_array($this, [self::Pending, self::Active], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Dismissed, self::Completed], true);
    }
}
