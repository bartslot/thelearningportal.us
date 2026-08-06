<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\NarrationCreditReason;
use App\Models\NarrationCredit;
use App\Models\User;
use App\Support\NarrationBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The webhook is the only thing in the app that creates money's worth of credit, so it is the only
 * thing worth attacking. Two properties matter above the rest:
 *
 *  - it believes nothing it cannot verify against STRIPE_WEBHOOK_SECRET, and
 *  - Stripe can deliver the same thing as often as it likes without granting twice.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret_for_this_suite_only';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.stripe.webhook_secret', self::SECRET);
    }

    /** Sign a payload the way Stripe does: t=<ts>,v1=<hmac of "ts.payload">. */
    private function postEvent(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call(
            'POST',
            route('stripe.webhook'),
            server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    private function sessionEvent(User $teacher, array $overrides = [], string $eventId = 'evt_checkout_1'): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $overrides['type'] ?? 'checkout.session.completed',
            'data' => ['object' => array_merge([
                'id' => 'cs_test_session_1',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'client_reference_id' => (string) $teacher->id,
                'metadata' => ['teacher_id' => (string) $teacher->id, 'characters' => '5000'],
                'amount_total' => 500,
                'currency' => 'eur',
                'payment_intent' => 'pi_test_1',
                'total_details' => ['amount_tax' => 87],
            ], $overrides['session'] ?? [])],
        ];
    }

    // ── Idempotency ─────────────────────────────────────────────────────────

    public function test_a_duplicated_webhook_grants_credit_only_once_and_still_answers_200(): void
    {
        // Stripe retries until it gets a 200. An error response would not get the duplicate looked
        // at, it would get the same duplicate redelivered with backoff for days.
        $teacher = User::factory()->create();

        $this->postEvent($this->sessionEvent($teacher))->assertOk();
        $this->postEvent($this->sessionEvent($teacher))->assertOk();
        $this->postEvent($this->sessionEvent($teacher))->assertOk();

        $this->assertSame(5000, NarrationBudget::creditCharacters($teacher->fresh()), 'a retry granted the pack again');
        $this->assertSame(1, NarrationCredit::forTeacher($teacher->id)->purchases()->count());
    }

    public function test_an_ideal_payment_announced_by_two_different_events_grants_once(): void
    {
        // iDEAL sends checkout.session.completed while still unpaid, then
        // async_payment_succeeded when the bank clears. Two event ids, one sale.
        $teacher = User::factory()->create();

        $this->postEvent($this->sessionEvent($teacher, [
            'session' => ['payment_status' => 'unpaid'],
        ], 'evt_ideal_pending'))->assertOk();

        $this->assertSame(0, NarrationBudget::creditCharacters($teacher->fresh()), 'credit was granted before the money arrived');

        $this->postEvent($this->sessionEvent($teacher, [
            'type' => 'checkout.session.async_payment_succeeded',
        ], 'evt_ideal_cleared'))->assertOk();

        $this->postEvent($this->sessionEvent($teacher, [
            'type' => 'checkout.session.async_payment_succeeded',
        ], 'evt_ideal_cleared_retry'))->assertOk();

        $this->assertSame(5000, NarrationBudget::creditCharacters($teacher->fresh()));
        $this->assertSame(1, NarrationCredit::forTeacher($teacher->id)->purchases()->count());
    }

    // ── Trust ───────────────────────────────────────────────────────────────

    public function test_a_request_we_cannot_verify_is_refused_and_grants_nothing(): void
    {
        $teacher = User::factory()->create();

        $this->postEvent($this->sessionEvent($teacher), signature: 't=1,v1=deadbeef')->assertStatus(400);

        $this->assertSame(0, NarrationBudget::creditCharacters($teacher->fresh()));
        $this->assertSame(0, NarrationCredit::count());
    }

    public function test_the_endpoint_refuses_to_run_at_all_without_a_signing_secret(): void
    {
        // Unsigned means unverifiable, and an unverifiable endpoint that grants credit is a free
        // credit machine for anyone who finds the URL.
        config()->set('services.stripe.webhook_secret', '');
        $teacher = User::factory()->create();

        $this->postEvent($this->sessionEvent($teacher))->assertStatus(500);

        $this->assertSame(0, NarrationCredit::count());
    }

    public function test_a_guest_demo_account_is_never_granted_credit(): void
    {
        $guest = User::factory()->create(['is_guest_demo' => true]);

        $this->postEvent($this->sessionEvent($guest))->assertOk();

        $this->assertSame(0, NarrationCredit::count());
        $this->assertSame(0, NarrationBudget::creditCharacters($guest->fresh()));
    }

    // ── What was actually paid ──────────────────────────────────────────────

    public function test_a_purchase_records_net_vat_and_gross_separately(): void
    {
        // A gross total on its own cannot be taken apart again at the end of a quarter, and the
        // rate differs per country.
        $teacher = User::factory()->create();

        $this->postEvent($this->sessionEvent($teacher))->assertOk();

        $purchase = NarrationCredit::forTeacher($teacher->id)->purchases()->firstOrFail();
        $this->assertSame(413, $purchase->amount_net_cents);
        $this->assertSame(87, $purchase->amount_vat_cents);
        $this->assertSame(500, $purchase->amount_gross_cents);
        $this->assertSame('eur', $purchase->currency);
    }

    public function test_a_purchase_carries_the_date_it_runs_out(): void
    {
        config()->set('billing.credit_pack.expires_after_days', 30);
        $teacher = User::factory()->create();

        $this->postEvent($this->sessionEvent($teacher))->assertOk();

        $purchase = NarrationCredit::forTeacher($teacher->id)->purchases()->firstOrFail();
        $this->assertNotNull($purchase->expires_at);
        $this->assertEqualsWithDelta(30, now()->diffInDays($purchase->expires_at, absolute: true), 1);
    }

    // ── Money going back ────────────────────────────────────────────────────

    public function test_a_refund_writes_a_negative_row_and_never_deletes_the_purchase(): void
    {
        $teacher = User::factory()->create();
        $this->postEvent($this->sessionEvent($teacher))->assertOk();

        $this->postEvent([
            'id' => 'evt_refund_1',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_test_1', 'object' => 'charge',
                'payment_intent' => 'pi_test_1', 'amount' => 500, 'amount_refunded' => 500,
            ]],
        ])->assertOk();

        $this->assertSame(1, NarrationCredit::forTeacher($teacher->id)->purchases()->count(), 'the purchase was deleted instead of reversed');
        $this->assertSame(1, NarrationCredit::where('reason', NarrationCreditReason::Refund)->count());
        $this->assertSame(0, NarrationBudget::creditCharacters($teacher->fresh()));
    }

    public function test_a_partial_refund_takes_back_the_same_share_of_the_characters(): void
    {
        $teacher = User::factory()->create();
        $this->postEvent($this->sessionEvent($teacher))->assertOk();

        // 200 of 500 cents back, so 2000 of 5000 characters.
        $this->postEvent([
            'id' => 'evt_refund_partial',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_test_1', 'object' => 'charge',
                'payment_intent' => 'pi_test_1', 'amount' => 500, 'amount_refunded' => 200,
            ]],
        ])->assertOk();

        $this->assertSame(3000, NarrationBudget::creditCharacters($teacher->fresh()));
    }

    public function test_a_repeated_refund_event_does_not_claw_back_twice(): void
    {
        $teacher = User::factory()->create();
        $this->postEvent($this->sessionEvent($teacher))->assertOk();

        $refund = [
            'id' => 'evt_refund_repeat',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_test_1', 'object' => 'charge',
                'payment_intent' => 'pi_test_1', 'amount' => 500, 'amount_refunded' => 500,
            ]],
        ];

        $this->postEvent($refund)->assertOk();
        $this->postEvent($refund)->assertOk();

        $this->assertSame(1, NarrationCredit::where('reason', NarrationCreditReason::Refund)->count());
        $this->assertSame(0, NarrationBudget::creditCharacters($teacher->fresh()));
    }

    public function test_a_chargeback_takes_back_the_whole_pack(): void
    {
        $teacher = User::factory()->create();
        $this->postEvent($this->sessionEvent($teacher))->assertOk();

        $this->postEvent([
            'id' => 'evt_dispute_1',
            'object' => 'event',
            'type' => 'charge.dispute.created',
            'data' => ['object' => [
                'id' => 'dp_test_1', 'object' => 'dispute',
                'payment_intent' => 'pi_test_1', 'amount' => 500,
            ]],
        ])->assertOk();

        $this->assertSame(0, NarrationBudget::creditCharacters($teacher->fresh()));
        $this->assertSame(1, NarrationCredit::where('reason', NarrationCreditReason::Chargeback)->count());
    }

    public function test_an_event_we_do_not_handle_is_acknowledged_and_changes_nothing(): void
    {
        // Stripe sends plenty nobody asked for. Acknowledging is the whole job.
        $this->postEvent([
            'id' => 'evt_unrelated',
            'object' => 'event',
            'type' => 'customer.updated',
            'data' => ['object' => ['id' => 'cus_1', 'object' => 'customer']],
        ])->assertOk();

        $this->assertSame(0, NarrationCredit::count());
    }
}
