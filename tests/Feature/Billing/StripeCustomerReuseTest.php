<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Billing\StripeCheckout;
use App\Services\Billing\StripeCustomers;
use App\Support\NarrationCreditPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A teacher gets ONE Stripe Customer, for the life of the account.
 *
 * Checkout used to pass customer_creation=always, so buying three packs made three unrelated
 * Customers. Merely untidy for a one-off payment; fatal for everything after it, because an invoice
 * and a subscription both attach to a Customer and a school split across three cannot be billed.
 */
class StripeCustomerReuseTest extends TestCase
{
    use RefreshDatabase;

    private function customers(): StripeCustomers
    {
        return app(StripeCustomers::class);
    }

    private function useTestKey(): void
    {
        config()->set('services.stripe.secret', 'sk_test_pinned_for_this_suite');
    }

    private function useLiveKey(): void
    {
        config()->set('services.stripe.secret', 'sk_live_pinned_for_this_suite');
    }

    // ── Reuse ───────────────────────────────────────────────────────────────

    public function test_a_stored_customer_is_reused_without_calling_stripe(): void
    {
        // No client is ever set on the service, so if this reached Stripe it would fail loudly
        // rather than quietly passing.
        $this->useTestKey();
        $teacher = User::factory()->create([
            'stripe_customer_id' => 'cus_existing', 'stripe_customer_livemode' => false,
        ]);

        $this->assertSame('cus_existing', $this->customers()->idFor($teacher));
    }

    public function test_a_teacher_with_no_customer_yet_has_nothing_stored(): void
    {
        $this->useTestKey();
        $teacher = User::factory()->create();

        $this->assertNull($this->customers()->storedIdFor($teacher));
    }

    // ── The go-live trap ────────────────────────────────────────────────────

    public function test_a_test_mode_customer_is_not_used_with_a_live_key(): void
    {
        // The day the live keys go in, every returning teacher would otherwise hit "No such
        // customer" on their next purchase, because a test cus_ and a live cus_ look identical and
        // neither works with the other's key.
        $teacher = User::factory()->create([
            'stripe_customer_id' => 'cus_made_in_test', 'stripe_customer_livemode' => false,
        ]);

        $this->useLiveKey();

        $this->assertNull(
            $this->customers()->storedIdFor($teacher),
            'a test-mode customer was about to be used against a live key',
        );
    }

    public function test_a_live_customer_is_not_used_with_a_test_key(): void
    {
        $teacher = User::factory()->create([
            'stripe_customer_id' => 'cus_made_in_live', 'stripe_customer_livemode' => true,
        ]);

        $this->useTestKey();

        $this->assertNull($this->customers()->storedIdFor($teacher));
    }

    public function test_a_customer_stored_before_the_mode_was_recorded_is_not_trusted(): void
    {
        // Rows written before the livemode column existed cannot say which mode they came from.
        $this->useTestKey();
        $teacher = User::factory()->create([
            'stripe_customer_id' => 'cus_unknown_origin', 'stripe_customer_livemode' => null,
        ]);

        $this->assertNull($this->customers()->storedIdFor($teacher));
    }

    public function test_forgetting_a_customer_clears_both_the_id_and_the_mode(): void
    {
        $this->useTestKey();
        $teacher = User::factory()->create([
            'stripe_customer_id' => 'cus_stale', 'stripe_customer_livemode' => false,
        ]);

        $this->customers()->forget($teacher);

        $this->assertNull($teacher->fresh()->stripe_customer_id);
        $this->assertNull($teacher->fresh()->stripe_customer_livemode);
    }

    // ── Guests ──────────────────────────────────────────────────────────────

    public function test_a_guest_demo_account_never_gets_a_customer(): void
    {
        $this->useTestKey();
        $guest = User::factory()->create(['is_guest_demo' => true]);

        $this->expectException(RuntimeException::class);
        $this->customers()->idFor($guest);
    }

    // ── The payload Stripe actually receives ────────────────────────────────

    public function test_checkout_sends_the_customer_and_never_asks_stripe_to_make_one(): void
    {
        $this->useTestKey();
        $teacher = User::factory()->create(['locale' => 'nl']);

        $payload = app(StripeCheckout::class)->sessionPayload($teacher, 'cus_abc', 'https://ok', 'https://no');

        $this->assertSame('cus_abc', $payload['customer']);
        $this->assertArrayNotHasKey('customer_creation', $payload, 'checkout is still making a Customer per payment');
        $this->assertArrayNotHasKey('customer_email', $payload, 'customer_email cannot be sent alongside customer');
    }

    public function test_checkout_lets_stripe_write_the_address_back_to_the_customer(): void
    {
        // Stripe REQUIRES customer_update.address=auto when automatic_tax runs against an existing
        // customer. Without it the session is refused outright and nobody can buy anything.
        $this->useTestKey();
        $teacher = User::factory()->create();

        $payload = app(StripeCheckout::class)->sessionPayload($teacher, 'cus_abc', 'https://ok', 'https://no');

        $this->assertSame('auto', $payload['customer_update']['address'] ?? null);
    }

    public function test_the_price_is_still_five_euro_inclusive_of_vat(): void
    {
        // Guards the one number in this system that is expensive to get wrong.
        $this->useTestKey();
        $teacher = User::factory()->create();

        $price = app(StripeCheckout::class)
            ->sessionPayload($teacher, 'cus_abc', 'https://ok', 'https://no')['line_items'][0]['price_data'];

        $this->assertSame(500, $price['unit_amount']);
        $this->assertSame('eur', $price['currency']);
        // EXCLUSIVE: the 5.00 is what we keep and Stripe adds the buyer's VAT on top, so a Dutch
        // teacher pays 6.05. Flipping this to 'inclusive' silently cuts revenue by the VAT rate —
        // 87 cents in every 5 euro at 21% — which is why it is asserted rather than assumed.
        $this->assertSame('exclusive', $price['tax_behavior']);
        $this->assertSame(NarrationCreditPack::amountCents(), $price['unit_amount']);
    }

    public function test_the_line_item_declares_a_tax_code(): void
    {
        // Not cosmetic and not optional: Stripe refuses a session whose line item has no tax code,
        // so without this nobody can buy anything at all. It also decides the VAT rate, which is
        // why it is a config value with the reasoning written down next to it.
        $this->useTestKey();
        $teacher = User::factory()->create();

        $product = app(StripeCheckout::class)
            ->sessionPayload($teacher, 'cus_abc', 'https://ok', 'https://no')['line_items'][0]['price_data']['product_data'];

        $this->assertNotEmpty($product['tax_code'] ?? null, 'Stripe will refuse this session');
        $this->assertStringStartsWith('txcd_', $product['tax_code']);

        // Pinned, not merely well-formed. This code carries the EDUCATIONAL classification and with
        // it the reduced rate; txcd_10000000 ("General - Electronically Supplied Services") is the
        // general-rate code and would have Stripe charge 21% instead of 9%. Both are valid txcd_
        // strings, so a shape check would have let that swap through silently.
        $this->assertSame('txcd_20060258', $product['tax_code']);
    }

    public function test_the_screen_shows_the_reduced_education_rate(): void
    {
        // 9%, not 21%: this is an education platform. The gross is what a consumer must be shown,
        // so a wrong rate here is a wrong price on the button, not just a wrong comment.
        $this->assertSame(0.09, NarrationCreditPack::displayVatRate());
        $this->assertSame(545, NarrationCreditPack::grossCents());
    }

    public function test_the_session_carries_the_teacher_and_the_character_count(): void
    {
        $this->useTestKey();
        $teacher = User::factory()->create();

        $payload = app(StripeCheckout::class)->sessionPayload($teacher, 'cus_abc', 'https://ok', 'https://no');

        $this->assertSame((string) $teacher->id, $payload['client_reference_id']);
        $this->assertSame((string) NarrationCreditPack::characters(), $payload['metadata']['characters']);
        // Repeated onto the PaymentIntent so refunds and disputes, which never mention the session,
        // can still be traced back to a teacher.
        $this->assertSame((string) $teacher->id, $payload['payment_intent_data']['metadata']['teacher_id']);
    }
}
