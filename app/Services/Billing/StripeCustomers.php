<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\User;
use RuntimeException;
use Stripe\StripeClient;

/**
 * One Stripe Customer per teacher, for the whole life of the account.
 *
 * Checkout used to create a new Customer every time somebody paid, so a teacher who bought three
 * packs became three unrelated Customers. That is merely untidy for a one-off payment and fatal for
 * everything after it: an invoice and a subscription both attach to a Customer, and a school spread
 * across three of them cannot be billed, refunded as one, or shown its own receipt history.
 *
 * The id is cached on the user, so the happy path costs no API call at all.
 */
final class StripeCustomers
{
    private ?StripeClient $client = null;

    /**
     * Swap the Stripe client, for tests.
     *
     * Not a constructor argument on purpose: StripeClient can be built with no arguments, so the
     * container would autowire a keyless one and every call would go out unauthenticated.
     */
    public function useClient(StripeClient $client): void
    {
        $this->client = $client;
    }

    /** Is this key a live one? Decides which stored Customer ids are usable. */
    public function keyIsLive(): bool
    {
        return str_starts_with((string) config('services.stripe.secret'), 'sk_live_');
    }

    /**
     * The Customer id already on file, or null if there is nothing usable.
     *
     * Null when the stored id was made in the other Stripe mode. A test `cus_...` and a live one
     * look identical and neither works with the other's key, so the day the live keys go in, every
     * returning teacher would otherwise hit "No such customer" on their next purchase.
     *
     * Pure: no API call, so the mismatch is caught before we waste a round trip on it.
     */
    public function storedIdFor(User $teacher): ?string
    {
        $id = $teacher->stripe_customer_id;

        if (! is_string($id) || $id === '') {
            return null;
        }

        // A row written before this column existed has a null mode. Treat it as unusable rather
        // than guessing, and let the next checkout mint a fresh one.
        return $teacher->stripe_customer_livemode === $this->keyIsLive() ? $id : null;
    }

    /** The teacher's Customer, making one the first time they buy anything. */
    public function idFor(User $teacher): string
    {
        if ($teacher->isGuestDemo()) {
            // A throwaway account pruned after 24 hours has no business owning a Customer record.
            throw new RuntimeException('A demo guest cannot have a Stripe customer.');
        }

        return $this->storedIdFor($teacher) ?? $this->create($teacher);
    }

    /**
     * Forget the stored Customer so the next call makes a new one.
     *
     * For when Stripe says the id no longer exists — deleted in the dashboard, or the account's
     * test data was wiped. Self-healing beats a teacher who can never buy again.
     */
    public function forget(User $teacher): void
    {
        $teacher->forceFill(['stripe_customer_id' => null, 'stripe_customer_livemode' => null])->save();
    }

    private function create(User $teacher): string
    {
        $customer = $this->client()->customers->create([
            'email' => $teacher->email,
            'name' => $teacher->name,
            // So a human looking at a payment in the Stripe dashboard can find the account.
            'metadata' => ['teacher_id' => (string) $teacher->id],
            'preferred_locales' => [$teacher->locale ?: 'en'],
        ]);

        $teacher->forceFill([
            'stripe_customer_id' => $customer->id,
            'stripe_customer_livemode' => $this->keyIsLive(),
        ])->save();

        return $customer->id;
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) config('services.stripe.secret'));
    }
}
