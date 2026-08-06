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
 * The price is EXCLUSIVE of VAT (Bart's decision, 2026-08-06): 5.00 euro is what WE keep, and the
 * VAT owed at the buyer's own country rate is added on top at checkout. A Dutch teacher pays 6.05,
 * a German 5.95, an Italian 6.10; a school with a valid VAT number is reverse-charged and pays
 * exactly 5.00. The line item goes to Stripe with tax_behavior=exclusive.
 *
 * Because of that, EVERY consumer-facing price has to show the gross figure. EU price-indication
 * rules require the total a consumer actually pays to be the prominent one, so the buy screen leads
 * with "6.05" and puts "5.00 excl. VAT" beside it — grossLabel() below, not priceLabel(). The exact
 * rate is Stripe Tax's answer for the buyer's country; grossLabel() shows the local default so the
 * screen is honest before Stripe has been asked.
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

    /**
     * The Stripe tax code that decides our VAT rate. See config/billing.php for the reasoning.
     *
     * Not optional: Stripe refuses a Checkout session whose line item has no tax code.
     */
    public static function taxCode(): string
    {
        return (string) config('billing.credit_pack.tax_code', 'txcd_10000000');
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

    /**
     * "€5.00" — the NET price, what we keep. Display only; money is compared and stored in cents.
     *
     * This is not the figure to lead a buy button with. The price is exclusive of VAT, so a consumer
     * pays more than this; see grossLabel().
     */
    public static function priceLabel(?string $locale = null): string
    {
        return (string) Number::currency(
            self::amountCents() / 100,
            strtoupper(self::currency()),
            $locale ?? app()->getLocale(),
        );
    }

    /**
     * The VAT rate we show BEFORE Stripe Tax has computed the buyer's real one, per country.
     *
     * Only used to render an honest gross figure on the screen. The rate that is actually charged
     * and stored is whatever Stripe returns for the buyer's address, which is why nothing downstream
     * reads this.
     */
    private const DEFAULT_VAT_RATE = [
        'NL' => 0.21, 'BE' => 0.21, 'DE' => 0.19, 'FR' => 0.20, 'IT' => 0.22,
        'ES' => 0.21, 'AT' => 0.20, 'IE' => 0.23, 'LU' => 0.17, 'GB' => 0.20,
    ];

    private const FALLBACK_VAT_RATE = 0.21;

    /** Gross price in cents for a country, rounded the way an invoice rounds. */
    public static function grossCents(?string $country = null): int
    {
        $rate = self::DEFAULT_VAT_RATE[strtoupper((string) $country)] ?? self::FALLBACK_VAT_RATE;

        return (int) round(self::amountCents() * (1 + $rate));
    }

    /**
     * "€6.05" — what the teacher actually pays, VAT included.
     *
     * THIS is the figure a buy screen leads with. EU price-indication rules require the total a
     * consumer pays to be the prominent one, and with an exclusive price the net figure is not it.
     */
    public static function grossLabel(?string $country = null, ?string $locale = null): string
    {
        return (string) Number::currency(
            self::grossCents($country) / 100,
            strtoupper(self::currency()),
            $locale ?? app()->getLocale(),
        );
    }
}
