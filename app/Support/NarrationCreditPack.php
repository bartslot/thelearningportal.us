<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/**
 * The one thing a teacher can buy: 5000 characters of script editing for 5 euro.
 *
 * There is deliberately a single pack. A teacher who needs more buys it again, which is easier to
 * explain on the screen and easier to reason about in the ledger than a tier table nobody asked
 * for yet.
 *
 * The price is INCLUSIVE of VAT: the teacher pays exactly 5.00 euro and the VAT owed at their own
 * country's rate comes out of it (about 0.87 at the Dutch 21%, netting about 4.13). That is what EU
 * price-indication rules expect of a consumer-facing price, and most buyers here are individual
 * teachers rather than schools with a purchase order. Stripe Tax works the rate out per country, so
 * the line item is sent with tax_behavior=inclusive and nothing is added at checkout.
 *
 * The numbers live in config/billing.php. Read them through here.
 */
final class NarrationCreditPack
{
    /** Characters granted per purchase. Spendable across any of the teacher's lessons. */
    public static function characters(): int
    {
        return (int) config('billing.credit_pack.characters', 5000);
    }

    /** What the teacher pays, in integer cents, VAT included. */
    public static function amountCents(): int
    {
        return (int) config('billing.credit_pack.amount_cents', 500);
    }

    public static function currency(): string
    {
        return strtolower((string) config('billing.credit_pack.currency', 'eur'));
    }

    /** Days bought credit lasts. Zero means it never expires. */
    public static function expiryDays(): int
    {
        return max(0, (int) config('billing.credit_pack.expires_after_days', 30));
    }

    public static function expires(): bool
    {
        return self::expiryDays() > 0;
    }

    /** When credit bought now runs out, or null when expiry is switched off. */
    public static function expiresAt(?CarbonImmutable $boughtAt = null): ?CarbonImmutable
    {
        if (! self::expires()) {
            return null;
        }

        return ($boughtAt ?? CarbonImmutable::now())->addDays(self::expiryDays());
    }

    /** "€5.00", in the reader's language. Display only — money is compared and stored in cents. */
    public static function priceLabel(?string $locale = null): string
    {
        return (string) Number::currency(
            self::amountCents() / 100,
            strtoupper(self::currency()),
            $locale ?? app()->getLocale(),
        );
    }
}
