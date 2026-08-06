<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every movement of narration credit a teacher has ever had. Append-only: a balance is the SUM of
 * this table, never a column someone writes.
 *
 * A mutable `users.credits` integer would drift the first time two requests wrote it at once — two
 * autosaving panels, or a webhook arriving while the teacher edits — and nothing would record that
 * it had. Rows are cheap and a sum over an indexed teacher_id is fast; if it ever stops being fast,
 * cache the sum and keep this as the truth.
 *
 * `characters` is SIGNED and carries the direction: a purchase is positive, a spend, a refund and a
 * chargeback are all negative. Money never moves backwards here either — a refund is a new negative
 * row, never a deleted purchase, so the history of what happened survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('narration_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();

            // Signed: + bought, - spent/refunded/charged back. See App\Enums\NarrationCreditReason.
            $table->integer('characters');
            $table->string('reason', 32);

            // Which lesson a spend went on. Deliberately NOT cascadeOnDelete, unlike the rest of the
            // schema: deleting a lesson must not erase the record that a teacher paid for it.
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();

            // ── Expiry ────────────────────────────────────────────────────────────
            //
            // A purchase is a BATCH of characters with a date it dies on. Everything that draws it
            // down — a spend, a refund, a chargeback — names the batch it came out of, so we always
            // know how much of each batch is left and when that amount stops counting.
            //
            // Without the batch link a balance could only be "sum of everything", and a teacher who
            // topped up twice would have their spending taken off whichever batch the query happened
            // to reach first. Credit is spent soonest-expiry-first, which needs this.
            $table->foreignId('batch_id')->nullable()->constrained('narration_credits')->nullOnDelete();

            // Set on purchases only. Null on everything else, which inherits its batch's date.
            $table->timestamp('expires_at')->nullable();

            // ── Money, in integer cents ───────────────────────────────────────────
            //
            // Three columns rather than a single total, because the VAT rate depends on the
            // customer's country (21% NL, 19% DE, 22% IT, 0% for a reverse-charged school) and a
            // gross figure alone cannot be taken apart again afterwards. All null on spends, which
            // move characters and no money.
            $table->unsignedInteger('amount_net_cents')->nullable();     // what we keep
            $table->unsignedInteger('amount_vat_cents')->nullable();     // what the tax office gets
            $table->unsignedInteger('amount_gross_cents')->nullable();   // what the teacher paid
            $table->string('currency', 3)->nullable();

            // The Stripe object this row is idempotent on, and UNIQUE is the whole idempotency
            // story: Stripe retries a webhook until it gets a 200, and without this a retry would
            // grant the same 5000 characters again.
            //
            // For a PURCHASE this holds the Checkout Session id (cs_...), not the event id. One
            // sale can be announced by two different events — an iDEAL payment sends
            // checkout.session.completed while still unpaid and async_payment_succeeded when the
            // bank clears it — and both must add up to one grant. The session is the sale.
            //
            // For a refund or a dispute it holds the event id (evt_...), because each of those IS a
            // separate thing that happened. Null for spends, which no webhook causes; Postgres
            // allows any number of NULLs in a unique index.
            $table->string('stripe_event_id')->nullable()->unique();

            // Ties a refund or a dispute back to the purchase it reverses.
            $table->string('stripe_payment_intent_id')->nullable();

            // Append-only, so there is no updated_at: a row is never edited after it is written.
            $table->timestamp('created_at')->useCurrent();

            $table->index('teacher_id');                    // the balance sum
            $table->index('stripe_payment_intent_id');      // refund -> original purchase
            $table->index(['teacher_id', 'expires_at']);    // "which batches are still alive"
            $table->index('batch_id');                      // a batch's drawdowns
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('narration_credits');
    }
};
