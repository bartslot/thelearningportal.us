<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\NarrationCreditReason;
use App\Models\Lesson;
use App\Models\NarrationCredit;
use App\Models\User;
use App\Services\Billing\NarrationCreditLedger;
use App\Support\NarrationBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Spending bought credit.
 *
 * The thing that must never happen here is the same characters being spent twice. Two wizard panels
 * autosave at once, both read "500 left", both spend it, and we pay ElevenLabs for an edit nobody
 * ever paid us for. So checking and charging are one locked operation, and these pin that down.
 */
class NarrationCreditSpendTest extends TestCase
{
    use RefreshDatabase;

    private function teacherWithLesson(): array
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'title' => 'T', 'topic' => 'T', 'subject' => 'history',
            'language' => 'en', 'grade_level' => '7',
        ]);

        return [$teacher, $lesson];
    }

    /** A purchase batch, straight into the ledger. */
    private function grant(User $teacher, int $characters, ?string $expiresAt = null): NarrationCredit
    {
        return NarrationCredit::create([
            'teacher_id' => $teacher->id,
            'characters' => $characters,
            'reason' => NarrationCreditReason::Purchase,
            'expires_at' => $expiresAt ?? now()->addDays(30),
            'amount_net_cents' => 413,
            'amount_vat_cents' => 87,
            'amount_gross_cents' => 500,
            'currency' => 'eur',
            'stripe_event_id' => 'cs_test_'.uniqid(),
        ]);
    }

    /** Use up the lesson's free allowance so the next charge has to reach for credits. */
    private function exhaustFreeAllowance(Lesson $lesson): void
    {
        $lesson->update(['narration_edit_characters' => NarrationBudget::FREE_CHARACTERS_PER_LESSON]);
    }

    // ── The one that matters ────────────────────────────────────────────────

    public function test_a_teacher_cannot_spend_the_same_credits_twice(): void
    {
        [$teacher, $lesson] = $this->teacherWithLesson();
        $this->exhaustFreeAllowance($lesson);
        $this->grant($teacher, 100);

        // Arrange: exactly 100 characters, and two edits that each want all 100.
        $this->assertSame(100, NarrationBudget::creditCharacters($teacher->fresh()));

        $first = NarrationBudget::charge($lesson, 100);
        $second = NarrationBudget::charge($lesson->fresh(), 100);

        $this->assertTrue($first, 'the first edit could not spend credit it had');
        $this->assertFalse($second, 'the same 100 characters were spent a second time');

        // The ledger must land on nothing left, never on a debt: -100 would mean we paid
        // ElevenLabs for an edit the teacher never bought.
        $this->assertSame(0, app(NarrationCreditLedger::class)->rawBalanceFor($teacher->id));
        $this->assertSame(0, NarrationBudget::creditCharacters($teacher->fresh()));
    }

    public function test_the_charge_locks_the_teacher_before_deciding(): void
    {
        // The test above proves the arithmetic. This proves the guard that makes the arithmetic
        // safe under two requests at once, which no sequential test can show on its own.
        [$teacher, $lesson] = $this->teacherWithLesson();
        $this->exhaustFreeAllowance($lesson);
        $this->grant($teacher, 500);

        DB::enableQueryLog();
        NarrationBudget::charge($lesson, 100);
        $log = DB::getRawQueryLog();
        DB::disableQueryLog();

        $locking = array_filter($log, fn (array $q): bool => str_contains(strtolower($q['raw_query']), 'for update')
            && str_contains(strtolower($q['raw_query']), 'users'));

        $this->assertNotEmpty($locking, 'charge() decided the balance without locking the teacher row');
    }

    public function test_a_charge_reads_the_balance_as_it_is_now_not_as_the_page_loaded_it(): void
    {
        // The stale-read failure the lock exists to prevent: a component holds a Lesson from the
        // start of the request while another request spends everything.
        [$teacher, $lesson] = $this->teacherWithLesson();
        $this->exhaustFreeAllowance($lesson);
        $this->grant($teacher, 100);

        $stale = Lesson::find($lesson->id);                  // what the open panel is holding
        NarrationBudget::charge($lesson->fresh(), 100);      // the other request spends the lot

        $this->assertFalse(
            NarrationBudget::charge($stale, 100),
            'a charge trusted a balance read before another request spent it',
        );
    }

    // ── Free allowance first ────────────────────────────────────────────────

    public function test_the_free_allowance_is_spent_before_any_bought_credit(): void
    {
        [$teacher, $lesson] = $this->teacherWithLesson();
        $this->grant($teacher, 5000);

        NarrationBudget::charge($lesson, 400);

        $this->assertSame(400, NarrationBudget::spentOn($lesson->fresh()), 'the free allowance was not used first');
        $this->assertSame(5000, NarrationBudget::creditCharacters($teacher->fresh()), 'bought credit was spent while free characters were still available');
    }

    public function test_an_edit_spanning_both_pots_takes_the_rest_of_the_free_allowance_then_credit(): void
    {
        [$teacher, $lesson] = $this->teacherWithLesson();
        $lesson->update(['narration_edit_characters' => 900]);   // 100 free characters left
        $this->grant($teacher, 5000);

        NarrationBudget::charge($lesson, 300);

        $this->assertSame(1000, NarrationBudget::spentOn($lesson->fresh()), 'the free allowance was not drained first');
        $this->assertSame(4800, NarrationBudget::creditCharacters($teacher->fresh()), 'the wrong number of characters came off the credits');
    }

    // ── Expiry ──────────────────────────────────────────────────────────────

    public function test_credit_is_spent_soonest_expiry_first(): void
    {
        // A teacher who tops up twice must lose the batch that was going to die anyway.
        [$teacher, $lesson] = $this->teacherWithLesson();
        $this->exhaustFreeAllowance($lesson);
        $soon = $this->grant($teacher, 100, now()->addDays(3)->toDateTimeString());
        $later = $this->grant($teacher, 100, now()->addDays(30)->toDateTimeString());

        NarrationBudget::charge($lesson, 100);

        $spentFromSoon = (int) NarrationCredit::where('batch_id', $soon->id)->sum('characters');
        $spentFromLater = (int) NarrationCredit::where('batch_id', $later->id)->sum('characters');

        $this->assertSame(-100, $spentFromSoon, 'the batch expiring first was not the one spent');
        $this->assertSame(0, $spentFromLater);
    }

    public function test_expired_credit_cannot_be_spent(): void
    {
        [$teacher, $lesson] = $this->teacherWithLesson();
        $this->exhaustFreeAllowance($lesson);
        $this->grant($teacher, 5000, now()->subDay()->toDateTimeString());

        $this->assertSame(0, NarrationBudget::creditCharacters($teacher->fresh()));
        $this->assertFalse(NarrationBudget::charge($lesson, 10), 'expired credit was spent');
    }

    public function test_expired_credit_is_never_deleted_and_can_still_be_shown(): void
    {
        // A teacher asking "where did my credits go" has to be able to be told.
        [$teacher] = $this->teacherWithLesson();
        $this->grant($teacher, 5000, now()->subDay()->toDateTimeString());

        $expired = app(NarrationCreditLedger::class)->expiredBatchesFor($teacher->id);

        $this->assertSame(1, NarrationCredit::forTeacher($teacher->id)->count(), 'an expired row was deleted');
        $this->assertSame(5000, (int) $expired->sum('characters'));
    }

    public function test_credit_never_expires_when_the_window_is_switched_off(): void
    {
        config()->set('billing.credit_pack.expires_after_days', 0);

        [$teacher, $lesson] = $this->teacherWithLesson();
        $this->exhaustFreeAllowance($lesson);
        NarrationCredit::create([
            'teacher_id' => $teacher->id, 'characters' => 500,
            'reason' => NarrationCreditReason::Purchase, 'expires_at' => null,
            'stripe_event_id' => 'cs_test_forever',
        ]);

        $this->assertSame(500, NarrationBudget::creditCharacters($teacher->fresh()));
        $this->assertTrue(NarrationBudget::charge($lesson, 500));
    }

    // ── The ledger's own rules ──────────────────────────────────────────────

    public function test_a_ledger_row_cannot_be_edited_or_deleted(): void
    {
        [$teacher] = $this->teacherWithLesson();
        $row = $this->grant($teacher, 5000);

        $this->expectException(LogicException::class);
        $row->update(['characters' => 999999]);
    }

    public function test_a_guest_demo_account_has_no_credit_and_cannot_be_given_any(): void
    {
        $guest = User::factory()->create(['is_guest_demo' => true]);
        $this->grant($guest, 5000);   // even with rows in the table

        $this->assertSame(0, NarrationBudget::creditCharacters($guest->fresh()));
    }
}
