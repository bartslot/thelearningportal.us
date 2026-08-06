<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enums\NarrationCreditReason;
use App\Models\NarrationCredit;
use App\Models\User;
use App\Services\Billing\NarrationCreditLedger;
use App\Support\NarrationCreditPack;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * The only place narration credit is created.
 *
 * Credit is granted HERE and never on the redirect back from Stripe. The success URL is an ordinary
 * URL: a teacher can bookmark it, share it, or simply type it, and none of that means a payment
 * happened. `checkout.session.completed` does.
 *
 * Every response that is not a forged request is a 200, including the ones that change nothing.
 * Stripe retries anything else, with backoff, for days — so an error here does not get a duplicate
 * looked at, it gets the same duplicate delivered again and again.
 */
final class StripeWebhookController
{
    public function __construct(private readonly NarrationCreditLedger $ledger) {}

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            // Unsigned means unverifiable, and an unverifiable request that can grant credit is a
            // free credit machine for anyone who finds the URL. Refuse to run at all.
            Log::error('Stripe webhook hit with no STRIPE_WEBHOOK_SECRET configured.');

            return response('Webhook not configured.', 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            // The one case that is genuinely not from Stripe. 400, and nothing is touched.
            Log::warning('Rejected a Stripe webhook with a bad signature.', ['error' => $e->getMessage()]);

            return response('Invalid signature.', 400);
        }

        match ($event->type) {
            // Paid immediately (card). For iDEAL and other delayed methods this can arrive still
            // unpaid, and the money is confirmed by the async event below instead.
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->grantFromSession($event),

            'charge.refunded' => $this->reverse($event, NarrationCreditReason::Refund),
            'charge.dispute.created' => $this->reverse($event, NarrationCreditReason::Chargeback),

            default => null,   // Stripe sends plenty we did not ask for; acknowledging is enough
        };

        return response('', 200);
    }

    /**
     * Grant the pack a completed checkout paid for.
     *
     * Idempotent on the CHECKOUT SESSION rather than on the event: a card payment sends
     * `completed`, while iDEAL can send `completed` and then `async_payment_succeeded` for the same
     * session. Those are two different event ids and one single sale. The unique index on
     * stripe_event_id is what enforces it, so two deliveries racing each other both try to insert
     * and exactly one wins.
     */
    private function grantFromSession(Event $event): void
    {
        $session = $event->data->object;
        $sessionId = (string) ($session->id ?? '');

        if (($session->payment_status ?? null) !== 'paid') {
            // Still waiting on the bank. async_payment_succeeded will arrive when it clears, and
            // async_payment_failed if it never does.
            Log::info('Stripe checkout session is not paid yet, granting nothing.', [
                'session_id' => $sessionId,
                'payment_status' => $session->payment_status ?? null,
            ]);

            return;
        }

        $teacher = $this->teacherFor($session);
        if (! $teacher) {
            Log::error('Paid Stripe checkout session has no teacher we recognise.', [
                'session_id' => $sessionId,
                'client_reference_id' => $session->client_reference_id ?? null,
            ]);

            return;
        }

        // The character count is read off the session, where it was pinned when the teacher started
        // the checkout, so changing the pack later cannot re-price a payment already in flight.
        $characters = (int) ($session->metadata->characters ?? NarrationCreditPack::characters());

        // The price is VAT-inclusive, so amount_total is the whole of what the teacher paid and the
        // tax Stripe worked out for their country comes out of it. Stored as three numbers because
        // the rate varies (21% NL, 19% DE, 22% IT, 0% for a reverse-charged school) and a gross
        // total on its own cannot be taken apart again at the end of a quarter.
        $gross = (int) ($session->amount_total ?? NarrationCreditPack::amountCents());
        $vat = (int) ($session->total_details->amount_tax ?? 0);

        $granted = $this->ledger->grantFromStripe(
            teacher: $teacher,
            characters: $characters,
            stripeEventId: $sessionId,
            reason: NarrationCreditReason::Purchase,
            netCents: max(0, $gross - $vat),
            vatCents: $vat,
            grossCents: $gross,
            currency: (string) ($session->currency ?? NarrationCreditPack::currency()),
            paymentIntentId: $this->paymentIntentId($session),
        );

        if ($granted) {
            Log::info('Granted narration credit from Stripe.', [
                'teacher_id' => $teacher->id,
                'characters' => $characters,
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Take back what a refunded or disputed payment bought.
     *
     * A negative row, never a deleted purchase: what happened, happened, and the history of it is
     * the only thing that explains a balance later. This can push a teacher below zero if they had
     * already spent the characters, which is the honest answer — they owe us, and they can spend
     * nothing until a purchase covers it.
     */
    private function reverse(Event $event, NarrationCreditReason $reason): void
    {
        $object = $event->data->object;
        $paymentIntentId = $this->paymentIntentId($object);

        if ($paymentIntentId === null) {
            Log::warning('Stripe reversal arrived with no payment intent to trace.', [
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return;
        }

        $purchase = $this->ledger->purchaseByPaymentIntent($paymentIntentId);
        if (! $purchase) {
            // A payment we never granted for: a duplicate charge, a test event, or a sale that
            // failed before the ledger saw it. Nothing to take back.
            Log::info('Stripe reversal for a payment with no purchase in the ledger.', [
                'event_id' => $event->id,
                'payment_intent' => $paymentIntentId,
            ]);

            return;
        }

        $teacher = $purchase->teacher;
        if (! $teacher) {
            Log::error('Purchase being reversed has no teacher.', ['purchase_id' => $purchase->id]);

            return;
        }

        $characters = $this->charactersToReverse($purchase, $object, $reason, $paymentIntentId);
        if ($characters <= 0) {
            return;   // already fully reversed by an earlier partial refund
        }

        $this->ledger->grantFromStripe(
            teacher: $teacher,
            characters: $characters,
            stripeEventId: $event->id,
            reason: $reason,
            grossCents: $this->reversedAmountCents($object, $reason, $purchase),
            currency: $purchase->currency,
            paymentIntentId: $paymentIntentId,
            // Attached to the batch it reverses, so the characters come off the same purchase they
            // were granted by and inherit that purchase's expiry.
            batchId: $purchase->id,
        );

        Log::info('Reversed narration credit.', [
            'teacher_id' => $teacher->id,
            'characters' => $characters,
            'reason' => $reason->value,
            'event_id' => $event->id,
        ]);
    }

    /**
     * How many characters this reversal takes back.
     *
     * A dispute pulls the whole payment, so it takes the whole pack. A refund can be partial, so it
     * takes the same share of the characters as it does of the money — and never more than is left
     * to take, because two partial refunds must not add up to more than one purchase.
     */
    private function charactersToReverse(
        NarrationCredit $purchase,
        mixed $object,
        NarrationCreditReason $reason,
        string $paymentIntentId,
    ): int {
        $bought = abs($purchase->characters);
        $paid = (int) ($purchase->amount_gross_cents ?: NarrationCreditPack::amountCents());

        $share = $reason === NarrationCreditReason::Chargeback || $paid <= 0
            ? $bought
            : (int) round($bought * min(1.0, ((int) ($object->amount_refunded ?? $paid)) / $paid));

        $alreadyReversed = (int) abs(
            NarrationCredit::where('stripe_payment_intent_id', $paymentIntentId)
                ->whereIn('reason', [NarrationCreditReason::Refund, NarrationCreditReason::Chargeback])
                ->sum('characters')
        );

        return max(0, min($share, $bought - $alreadyReversed));
    }

    private function reversedAmountCents(mixed $object, NarrationCreditReason $reason, NarrationCredit $purchase): int
    {
        $fallback = $purchase->amount_gross_cents ?? NarrationCreditPack::amountCents();

        return $reason === NarrationCreditReason::Chargeback
            ? (int) ($object->amount ?? $fallback)
            : (int) ($object->amount_refunded ?? $fallback);
    }

    /** Stripe hands the id back as a string, or as an expanded object when something expanded it. */
    private function paymentIntentId(mixed $object): ?string
    {
        $intent = $object->payment_intent ?? null;

        if (is_string($intent) && $intent !== '') {
            return $intent;
        }
        if (is_object($intent) && isset($intent->id)) {
            return (string) $intent->id;
        }

        return null;
    }

    /** client_reference_id is what we set; metadata is the belt to its braces. */
    private function teacherFor(mixed $session): ?User
    {
        $id = (int) ($session->client_reference_id ?? $session->metadata->teacher_id ?? 0);

        return $id > 0 ? User::find($id) : null;
    }
}
