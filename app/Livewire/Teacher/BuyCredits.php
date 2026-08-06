<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Models\Lesson;
use App\Models\NarrationCredit;
use App\Services\Billing\NarrationCreditLedger;
use App\Services\Billing\StripeCheckout;
use App\Support\NarrationBudget;
use App\Support\NarrationCreditPack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Buy narration credits, and see what has become of the ones already bought.
 *
 * Reached from the wizard when a lesson's free editing allowance runs out. Buying sends the teacher
 * to Stripe's own hosted checkout and brings them back here; the credit itself is written by the
 * webhook, so coming back is not what pays for it and this screen waits for the ledger to catch up
 * rather than assuming.
 */
#[Title('Narration credits')]
class BuyCredits extends Component
{
    /** How Stripe sent them back: 'success', 'cancelled', or nothing at all on a normal visit. */
    #[Url(as: 'checkout')]
    public ?string $checkout = null;

    /** The lesson they were editing when the allowance ran out, so we can offer the way back. */
    #[Url(as: 'lesson')]
    public ?int $lessonId = null;

    /** The balance the moment they returned, so an arriving payment is visible as a change. */
    public ?int $balanceOnReturn = null;

    /**
     * Refreshes left before we stop waiting on the webhook.
     *
     * A card clears in a second or two and iDEAL usually within ten, so about a minute of polling
     * covers nearly everything. After that the screen says so instead of spinning forever — the
     * credit still lands on its own, whether anyone is watching or not.
     */
    public int $pollsLeft = 0;

    private const MAX_POLLS = 15;

    public function mount(): void
    {
        if ($this->checkout === 'success') {
            $this->balanceOnReturn = $this->balance();
            $this->pollsLeft = self::MAX_POLLS;
        }

        // The lesson id arrives in the URL, so it is whatever the visitor typed. Drop it unless it
        // really is a lesson of theirs, rather than linking one teacher to another's work.
        if ($this->lessonId !== null && ! $this->returnLesson()) {
            $this->lessonId = null;
        }
    }

    /** The lesson to offer a way back to, if the URL named one they may open. */
    public function returnLesson(): ?Lesson
    {
        if ($this->lessonId === null) {
            return null;
        }

        $lesson = Lesson::find($this->lessonId);

        return $lesson && auth()->user()?->canManage($lesson) ? $lesson : null;
    }

    /** Send the teacher to Stripe. Nothing is granted here — see StripeWebhookController. */
    public function buy()
    {
        $teacher = auth()->user();

        if (! $teacher || $teacher->isGuestDemo()) {
            // Unreachable through the UI: the route is behind RestrictGuestDemo. A Livewire method
            // is callable by anyone who can open the component, so it is checked here as well.
            abort(403);
        }

        // Both URLs carry the lesson through Stripe, so a teacher who came from the editor lands
        // back with the way home still in front of them.
        $return = array_filter(['lesson' => $this->lessonId]);

        try {
            $url = app(StripeCheckout::class)->createSession(
                $teacher,
                route('teacher.credits.index', $return + ['checkout' => 'success']),
                route('teacher.credits.index', $return + ['checkout' => 'cancelled']),
            );
        } catch (Throwable $e) {
            Log::error('Could not start a Stripe checkout.', [
                'teacher_id' => $teacher->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('toast', type: 'error', message: __(
                'We could not open the payment screen just now. Please try again in a moment.'
            ));

            return null;
        }

        return redirect()->away($url);
    }

    /** Called by the poll while a payment is settling. */
    public function refreshBalance(): void
    {
        if ($this->pollsLeft > 0) {
            $this->pollsLeft--;
        }
    }

    /**
     * Where this visit stands, so the screen never claims something it does not know.
     *
     * Returning from Stripe is not proof of payment, and the credit is written by the webhook a
     * moment later, so "success" on its own is only ever "paid, waiting". It becomes 'arrived' when
     * the ledger actually moves, and 'delayed' if it has not moved by the time we stop looking.
     *
     * @return null|'arrived'|'waiting'|'delayed'|'cancelled'
     */
    public function checkoutState(): ?string
    {
        if ($this->checkout === 'cancelled') {
            return 'cancelled';
        }
        if ($this->checkout !== 'success') {
            return null;
        }
        if ($this->balance() > (int) $this->balanceOnReturn) {
            return 'arrived';
        }

        return $this->pollsLeft > 0 ? 'waiting' : 'delayed';
    }

    public function balance(): int
    {
        return NarrationBudget::creditCharacters(auth()->user());
    }

    /** The last handful of movements, newest first. Answers "where did my credits go". */
    public function history(): Collection
    {
        return NarrationCredit::forTeacher((int) auth()->id())
            ->with('lesson:id,title')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        $ledger = app(NarrationCreditLedger::class);

        return view('livewire.teacher.buy-credits', [
            'balance' => $this->balance(),
            'checkoutState' => $this->checkoutState(),
            'history' => $this->history(),
            'returnLesson' => $this->returnLesson(),
            'packCharacters' => NarrationCreditPack::characters(),
            'packPrice' => NarrationCreditPack::priceLabel(),
            'packExpiryDays' => NarrationCreditPack::expires() ? NarrationCreditPack::expiryDays() : null,
            'freePerLesson' => NarrationBudget::FREE_CHARACTERS_PER_LESSON,
            // When the soonest batch dies, so nobody discovers it only after it has.
            'nextExpiry' => $ledger->nextExpiryFor(auth()->user()),
            // What has already run out, because "where did my credits go" is the first question.
            'expiredCharacters' => (int) $ledger->expiredBatchesFor((int) auth()->id())->sum('characters'),
        ]);
    }
}
