<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\User;
use App\Support\NarrationCreditPack;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

/**
 * Starts a hosted Stripe Checkout for one credit pack.
 *
 * HOSTED, not a card form of our own: no card number, no IBAN and no CVC ever touches this server,
 * which takes the whole of PCI scope off the table. It is also how Dutch teachers get iDEAL, which
 * is what most of them will reach for, without us implementing a single payment method.
 *
 * This class only sends the teacher to Stripe. It grants nothing — the credits are written when the
 * webhook says the money arrived (StripeWebhookController). The success URL is just a URL, and a
 * visitor can type it.
 */
final class StripeCheckout
{
    /** Stripe's own language codes happen to match ours for every language we ship. */
    private const SUPPORTED_LOCALES = ['en', 'nl', 'de', 'fr', 'it'];

    /**
     * The Stripe client, built from the secret rather than injected.
     *
     * Deliberately NOT a constructor dependency. StripeClient can be constructed with no arguments,
     * so Laravel's container would happily autowire one with no API key into this service and every
     * call would go out unauthenticated. A test that needs a fake binds this whole service instead.
     */
    private ?StripeClient $client = null;

    /** False when STRIPE_SECRET is unset, which is the normal state in tests and on a fresh clone. */
    public function isConfigured(): bool
    {
        return is_string(config('services.stripe.secret')) && config('services.stripe.secret') !== '';
    }

    /**
     * Create the session and return the URL to send the teacher to.
     *
     * @throws RuntimeException when Stripe is not configured
     * @throws \Stripe\Exception\ApiErrorException when Stripe refuses the session
     */
    public function createSession(User $teacher, string $successUrl, string $cancelUrl): string
    {
        if ($teacher->isGuestDemo()) {
            // Belt and braces. The route is already behind RestrictGuestDemo; this makes sure no
            // other caller can ever start a checkout for a throwaway account.
            throw new RuntimeException('A demo guest cannot buy narration credits.');
        }
        if (! $this->isConfigured()) {
            throw new RuntimeException('Stripe is not configured: set STRIPE_SECRET in .env.');
        }

        $session = $this->client()->checkout->sessions->create($this->sessionPayload($teacher, $successUrl, $cancelUrl));

        $url = $session instanceof Session ? $session->url : null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Stripe returned a checkout session with no URL.');
        }

        return $url;
    }

    /** @return array<string, mixed> */
    private function sessionPayload(User $teacher, string $successUrl, string $cancelUrl): array
    {
        // Both sides of the sale, carried so the webhook can act without trusting anything the
        // browser sends back. client_reference_id is the teacher; the character count is pinned at
        // purchase time so changing the pack later cannot re-price a payment already in flight.
        $metadata = [
            'teacher_id' => (string) $teacher->id,
            'characters' => (string) NarrationCreditPack::characters(),
        ];

        $payload = [
            'mode' => 'payment',
            'client_reference_id' => (string) $teacher->id,
            'customer_email' => $teacher->email,
            'customer_creation' => 'always',
            'locale' => $this->stripeLocale($teacher),
            'metadata' => $metadata,
            // Repeated onto the PaymentIntent so a refund or a dispute, which arrive as charge
            // events and never mention the session, can still be traced back to a teacher.
            'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => NarrationCreditPack::currency(),
                    'unit_amount' => NarrationCreditPack::amountCents(),
                    // The 5 euro already contains the VAT — see NarrationCreditPack.
                    'tax_behavior' => 'inclusive',
                    'product_data' => [
                        'name' => __(':count characters of script editing', [
                            'count' => number_format(NarrationCreditPack::characters()),
                        ]),
                        'description' => NarrationCreditPack::expires()
                            ? __('Spendable across any of your lessons, for :days days.', [
                                'days' => NarrationCreditPack::expiryDays(),
                            ])
                            : __('Spendable across any of your lessons. Credits do not expire.'),
                    ],
                ],
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        if ((bool) config('services.stripe.automatic_tax')) {
            $payload['automatic_tax'] = ['enabled' => true];
            // Stripe Tax needs to know where the buyer is before it can pick a rate.
            $payload['billing_address_collection'] = 'required';
        }

        if ((bool) config('services.stripe.collect_tax_id')) {
            // A school entering a valid VAT number outside the Netherlands is reverse-charged.
            $payload['tax_id_collection'] = ['enabled' => true];
        }

        return $payload;
    }

    /** Show Stripe's own screen in the teacher's language, falling back to English. */
    private function stripeLocale(User $teacher): string
    {
        $locale = $teacher->locale ?: app()->getLocale();

        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'en';
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) config('services.stripe.secret'));
    }
}
