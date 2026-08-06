<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a row exists in the narration credit ledger.
 *
 * The sign of `characters` follows from the reason, and `signFor()` is the only place that mapping
 * lives — writing a positive refund would quietly hand a teacher their money AND their credits.
 */
enum NarrationCreditReason: string
{
    /** Stripe Checkout completed. The only thing that adds credit. */
    case Purchase = 'purchase';

    /** Script editing beyond the lesson's free allowance. */
    case Spend = 'spend';

    /** Money returned to the teacher, so the credits go back too. */
    case Refund = 'refund';

    /** The teacher's bank pulled the money back. Same effect as a refund, different story. */
    case Chargeback = 'chargeback';

    /** A human put something right by hand. Signed either way. */
    case Adjustment = 'adjustment';

    /** Which direction this reason moves a balance: 1 adds, -1 takes away. */
    public function sign(): int
    {
        return match ($this) {
            self::Purchase => 1,
            self::Spend, self::Refund, self::Chargeback => -1,
            self::Adjustment => 1,   // the caller passes an already-signed amount
        };
    }

    /** Did money change hands? Purchases and reversals have an amount; spends do not. */
    public function movesMoney(): bool
    {
        return in_array($this, [self::Purchase, self::Refund, self::Chargeback], true);
    }

    /** What a teacher reads on their own credit history. */
    public function label(): string
    {
        return match ($this) {
            self::Purchase => __('Credits bought'),
            self::Spend => __('Script editing'),
            self::Refund => __('Refunded'),
            self::Chargeback => __('Payment reversed'),
            self::Adjustment => __('Adjustment'),
        };
    }
}
