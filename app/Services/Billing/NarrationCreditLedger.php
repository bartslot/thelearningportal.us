<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\NarrationCreditReason;
use App\Models\Lesson;
use App\Models\NarrationCredit;
use App\Models\User;
use App\Support\NarrationCreditPack;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reads and writes the narration credit ledger (App\Models\NarrationCredit).
 *
 * Everything that changes a balance goes through here, and everything that changes a balance holds
 * a lock on the teacher's row while it does. Two panels autosaving at once would otherwise both
 * read "500 left" and both spend it; so would a webhook landing mid-edit.
 *
 * A purchase is a BATCH: a number of characters with a date they stop counting. A balance is
 * therefore not the sum of every row, it is the sum of the batches still alive, and spending comes
 * off the batch that dies soonest. A teacher who tops up twice must not lose the wrong one.
 *
 * Nothing here decides WHETHER a teacher may spend — that is App\Support\NarrationBudget, which
 * owns the free-allowance-then-credits policy and calls in through withOwnerLock().
 */
final class NarrationCreditLedger
{
    /** A teacher's unspent, unexpired characters. Never negative to a caller. */
    public function balanceFor(?User $teacher): int
    {
        if (! $teacher || $teacher->isGuestDemo()) {
            return 0;   // a throwaway demo account has no wallet and never gets one
        }

        return max(0, $this->rawBalanceFor($teacher->id));
    }

    /**
     * The balance as it actually stands, negative included.
     *
     * A balance CAN go below zero: a teacher who spends 5000 characters and then charges the
     * payment back leaves -5000 behind. Admins should see that; teachers should just see nothing
     * left to spend, which is what balanceFor() gives them.
     *
     * Every row maps to its batch through COALESCE(batch_id, id) — a purchase is its own batch, and
     * a spend points at the one it came out of — so this is one pass with no grouping. Rows whose
     * batch has run out of time simply do not count any more.
     */
    public function rawBalanceFor(int $teacherId): int
    {
        return (int) DB::table('narration_credits as c')
            ->join('narration_credits as b', 'b.id', '=', DB::raw('COALESCE(c.batch_id, c.id)'))
            ->where('c.teacher_id', $teacherId)
            ->where(function ($q): void {
                $q->whereNull('b.expires_at')->orWhere('b.expires_at', '>', now());
            })
            ->sum('c.characters');
    }

    /**
     * Batches that have died with characters still in them, newest first.
     *
     * Derived, never swept: no scheduled job writes expiry rows, because a cron that quietly stops
     * running would hand teachers credit they no longer own, and this project has had exactly that
     * happen. A query cannot drift. It is also what the buy screen shows a teacher who wants to
     * know where their characters went.
     *
     * @return Collection<int, object{id: int, expires_at: string, characters: int}>
     */
    public function expiredBatchesFor(int $teacherId): Collection
    {
        return $this->batchesFor($teacherId, alive: false);
    }

    /** When the teacher's soonest-dying live credit runs out, or null if none does. */
    public function nextExpiryFor(?User $teacher): ?CarbonImmutable
    {
        if (! $teacher || $teacher->isGuestDemo()) {
            return null;
        }

        $soonest = $this->batchesFor($teacher->id, alive: true)
            ->filter(fn (object $b): bool => $b->expires_at !== null)
            ->sortBy('expires_at')
            ->first();

        return $soonest ? CarbonImmutable::parse($soonest->expires_at) : null;
    }

    /**
     * Run $work with the teacher's ledger locked against every other writer.
     *
     * The lock is taken on the OWNER row rather than on the ledger rows themselves, because a
     * balance is a sum: locking the rows that exist does not stop someone inserting a new one.
     */
    public function withOwnerLock(int $teacherId, Closure $work): mixed
    {
        return DB::transaction(function () use ($teacherId, $work) {
            User::whereKey($teacherId)->lockForUpdate()->first();

            return $work();
        });
    }

    /**
     * Take characters off a teacher for editing a lesson's script, soonest expiry first.
     *
     * Call inside withOwnerLock() with the balance already checked — this writes, it does not
     * decide. One edit that crosses a batch boundary writes one row per batch it touches, so the
     * ledger always says exactly which characters were used up.
     *
     * @return list<NarrationCredit>
     */
    public function recordSpend(Lesson $lesson, int $characters): array
    {
        $left = abs($characters);
        $written = [];

        // Soonest expiry first, and a batch that never expires goes LAST: use up what is about to
        // be lost and keep what cannot be. Sorted here rather than left to the database, because
        // Postgres and MySQL disagree about where NULLs belong in an ORDER BY.
        $batches = $this->batchesFor((int) $lesson->teacher_id, alive: true)
            ->sortBy(fn (object $b): string => $b->expires_at ?? '9999-12-31 23:59:59');

        foreach ($batches as $batch) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $batch->characters);
            if ($take <= 0) {
                continue;
            }

            $written[] = NarrationCredit::create([
                'teacher_id' => $lesson->teacher_id,
                'characters' => -$take,
                'reason' => NarrationCreditReason::Spend,
                'lesson_id' => $lesson->id,
                'batch_id' => $batch->id,
            ]);
            $left -= $take;
        }

        if ($left > 0) {
            // The caller checked the balance under the same lock, so this should be unreachable.
            // If it ever is reached, the characters were still used at ElevenLabs and must be
            // recorded: an unattributed row keeps the ledger honest rather than losing the spend.
            Log::warning('Narration spend exceeded the live batches; recording the remainder unbatched.', [
                'lesson_id' => $lesson->id,
                'teacher_id' => $lesson->teacher_id,
                'unattributed' => $left,
            ]);

            $written[] = NarrationCredit::create([
                'teacher_id' => $lesson->teacher_id,
                'characters' => -$left,
                'reason' => NarrationCreditReason::Spend,
                'lesson_id' => $lesson->id,
            ]);
        }

        return $written;
    }

    /**
     * Grant bought characters, exactly once per Stripe object.
     *
     * Returns null when this one has already been recorded. Stripe retries a webhook until it gets
     * a 200 — after a timeout, after a deploy, sometimes for no visible reason — so "have I seen
     * this before" cannot be a SELECT followed by an INSERT: two retries arriving together both
     * find nothing and both grant. The unique index on stripe_event_id is what actually decides,
     * and losing that race is a duplicate-key error, which is the success path here.
     */
    public function grantFromStripe(
        User $teacher,
        int $characters,
        string $stripeEventId,
        NarrationCreditReason $reason = NarrationCreditReason::Purchase,
        ?int $netCents = null,
        ?int $vatCents = null,
        ?int $grossCents = null,
        ?string $currency = null,
        ?string $paymentIntentId = null,
        ?int $batchId = null,
    ): ?NarrationCredit {
        if ($teacher->isGuestDemo()) {
            // Nothing should be able to get a guest this far; if something did, it is a bug in the
            // fence and not a reason to give a 24-hour account a wallet.
            Log::warning('Refused narration credit for a guest demo account.', [
                'teacher_id' => $teacher->id,
                'stripe_event_id' => $stripeEventId,
            ]);

            return null;
        }

        try {
            // Wrapped in its own transaction so the duplicate-key collision below stays contained.
            // Postgres aborts an ENTIRE transaction on a failed statement and refuses everything
            // afterwards, so catching the error is not enough if anything upstream had a
            // transaction open: the insert has to happen inside a savepoint that can be rolled back
            // on its own. Nested DB::transaction() is exactly that.
            return DB::transaction(fn (): NarrationCredit => NarrationCredit::create([
                'teacher_id' => $teacher->id,
                'characters' => $reason->sign() * abs($characters),
                'reason' => $reason,
                'batch_id' => $batchId,
                // Only a purchase starts a clock. A refund takes characters off the batch it
                // reverses and inherits that batch's date.
                'expires_at' => $reason === NarrationCreditReason::Purchase
                    ? NarrationCreditPack::expiresAt()
                    : null,
                'amount_net_cents' => $netCents,
                'amount_vat_cents' => $vatCents,
                'amount_gross_cents' => $grossCents,
                'currency' => $currency,
                'stripe_event_id' => $stripeEventId,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]));
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            // Already granted. The webhook should still answer 200: an error makes Stripe retry
            // harder, and there is nothing here to retry.
            Log::info('Stripe event already in the narration credit ledger, ignoring the retry.', [
                'stripe_event_id' => $stripeEventId,
            ]);

            return null;
        }
    }

    /** The purchase a refund or dispute reverses, so we take back what that payment bought. */
    public function purchaseByPaymentIntent(string $paymentIntentId): ?NarrationCredit
    {
        return NarrationCredit::where('stripe_payment_intent_id', $paymentIntentId)
            ->purchases()
            ->first();
    }

    /**
     * Purchase batches with characters still in them, alive or dead.
     *
     * `characters` on each row is what is LEFT of that batch, not what it started with: the join
     * subtracts every spend, refund and chargeback that named it.
     *
     * @return Collection<int, object{id: int, expires_at: ?string, characters: int}>
     */
    private function batchesFor(int $teacherId, bool $alive): Collection
    {
        $expiry = $alive
            ? fn ($q) => $q->whereNull('b.expires_at')->orWhere('b.expires_at', '>', now())
            : fn ($q) => $q->whereNotNull('b.expires_at')->where('b.expires_at', '<=', now());

        return collect(
            DB::table('narration_credits as b')
                ->leftJoin('narration_credits as d', 'd.batch_id', '=', 'b.id')
                ->select('b.id', 'b.expires_at')
                ->selectRaw('b.characters + COALESCE(SUM(d.characters), 0) as characters')
                ->where('b.teacher_id', $teacherId)
                ->where('b.reason', NarrationCreditReason::Purchase->value)
                ->where($expiry)
                ->groupBy('b.id', 'b.expires_at', 'b.characters')
                ->havingRaw('b.characters + COALESCE(SUM(d.characters), 0) > 0')
                ->orderBy('b.expires_at')
                ->get()
        )->map(fn (object $row): object => (object) [
            'id' => (int) $row->id,
            'expires_at' => $row->expires_at,
            'characters' => (int) $row->characters,
        ]);
    }

    /** Postgres 23505, MySQL 1062, SQLite 19 all mean the same thing: that row already exists. */
    private function isDuplicateKey(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23505', '23000'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
