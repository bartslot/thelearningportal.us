<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Billing\NarrationCreditLedger;
use Illuminate\Support\Facades\DB;

/**
 * How much narration a teacher may have re-spoken on one lesson.
 *
 * Narration is billed per character by ElevenLabs, and editing a script is the one action in the
 * app that turns a keystroke into an invoice. A teacher rewriting the same scene twenty times, or
 * a script pasted in from somewhere, costs real money — so a lesson has an allowance.
 *
 * Only EDITED characters count. The script a lesson was generated with is already paid for; what
 * this meters is how much a teacher changes afterwards. A teacher who edits nothing spends nothing,
 * however long the lesson is — which is why the cap is not simply "scripts must be under 1000
 * characters" (our own Tasman scenes run 350-500 characters each and would be refused).
 *
 * There are two pots, and they are spent in this order:
 *
 *   1. The lesson's free allowance, counted in lessons.narration_edit_characters. Per lesson.
 *   2. The teacher's bought credits, summed from the narration_credits ledger. Across all lessons.
 *
 * Free first, always: a teacher's own money is the last thing to be touched.
 *
 * This class is the ONE place the app asks "can this teacher afford it". Nothing outside it should
 * read a balance to make that decision, and charge() is the only thing that may take the money.
 */
final class NarrationBudget
{
    /**
     * Edited characters included with any lesson, before credits.
     *
     * Roughly two paragraphs of narration: enough to fix a name, a date or a clumsy sentence, which
     * is what editing is actually for. Not enough to re-write a lesson end to end on someone else's
     * bill.
     */
    public const FREE_CHARACTERS_PER_LESSON = 1000;

    /**
     * Characters a teacher has bought and not yet spent, across every lesson they own.
     *
     * The sum of their narration_credits rows. A guest demo account always reads 0 — it cannot buy
     * and cannot be given credit. If this sum ever gets slow, cache it; keep the ledger as truth.
     */
    public static function creditCharacters(?User $teacher): int
    {
        return self::ledger()->balanceFor($teacher);
    }

    /** Everything this lesson may still spend: its free allowance plus the teacher's credits. */
    public static function allowanceFor(Lesson $lesson): int
    {
        return self::FREE_CHARACTERS_PER_LESSON + self::creditCharacters($lesson->teacher);
    }

    /**
     * Free-allowance characters already charged to this lesson.
     *
     * Only the free pot. Credit spending is not counted here because it has already been taken off
     * creditCharacters() — counting it in both places would charge the teacher twice over.
     */
    public static function spentOn(Lesson $lesson): int
    {
        return max(0, (int) $lesson->narration_edit_characters);
    }

    /** What is left of this lesson's own free allowance, before touching any credits. */
    public static function freeRemainingFor(Lesson $lesson): int
    {
        return max(0, self::FREE_CHARACTERS_PER_LESSON - self::spentOn($lesson));
    }

    public static function remainingFor(Lesson $lesson): int
    {
        return max(0, self::allowanceFor($lesson) - self::spentOn($lesson));
    }

    /**
     * What one edit costs: how far the new script differs from the old one, in characters.
     *
     * Deliberately the CHANGE and not the new length. Fixing a typo in a 400-character paragraph
     * costs about one character, not four hundred — anything else would make a teacher afraid to
     * touch their own lesson. `similar_text` gives the matching-character count directly, and it is
     * cheap enough at the sizes a scene script reaches.
     *
     * Shortening a script still costs something: removing words is an edit, and it still triggers
     * a re-narration of the whole scene.
     */
    public static function costOfEdit(string $before, string $after): int
    {
        $before = trim($before);
        $after = trim($after);

        if ($before === $after) {
            return 0;
        }
        if ($before === '') {
            return mb_strlen($after);
        }

        similar_text($before, $after, $percent);
        $shared = (int) round(mb_strlen($after) * ($percent / 100));

        return max(1, mb_strlen($after) - $shared);
    }

    /**
     * Whether this edit looks like it fits. ADVISORY ONLY — for showing a hint, never for deciding.
     *
     * Reads the balance without holding a lock, so by the time a caller acts on the answer another
     * request may have spent the same characters. charge() is the only safe way to find out, and it
     * answers the same question atomically.
     */
    public static function canAfford(Lesson $lesson, string $before, string $after): bool
    {
        return self::costOfEdit($before, $after) <= self::remainingFor($lesson);
    }

    /**
     * Take payment for an edit, free allowance first and credits after. Call BEFORE saving.
     *
     * Returns false when the teacher cannot afford it, having charged nothing — the caller must
     * then refuse the edit. Deciding and charging happen together, under a lock on the teacher's
     * row: two panels autosaving at once would otherwise both read the same balance and both spend
     * it, and the second edit would be narrated on money that was never there.
     *
     * This runs before the save rather than after because the alternative is worse. A save that
     * fails after a successful charge costs the teacher a few characters; a charge that fails after
     * a successful save costs us an ElevenLabs invoice with nothing recorded against it.
     */
    public static function charge(Lesson $lesson, int $characters): bool
    {
        if ($characters <= 0) {
            return true;   // an edit that changed nothing is free, and there is nothing to record
        }

        $ledger = self::ledger();

        $apply = static function () use ($lesson, $characters, $ledger): bool {
            // Re-read inside the lock: whatever another request charged is now visible, and the
            // balance this decision uses is the one that will still be true when it writes.
            $lesson->refresh();

            $fromFree = min($characters, self::freeRemainingFor($lesson));
            $fromCredits = $characters - $fromFree;

            if ($fromCredits > $ledger->balanceFor($lesson->teacher)) {
                return false;   // not enough in either pot, so nothing is taken from either
            }

            if ($fromFree > 0) {
                $lesson->increment('narration_edit_characters', $fromFree);
            }
            if ($fromCredits > 0) {
                $ledger->recordSpend($lesson, $fromCredits);
            }

            return true;
        };

        // Lock the teacher, who owns the credits. Every charge against any of their lessons passes
        // through the same row, so they all queue up behind each other. A lesson with no teacher
        // has no credits either, and only its own free counter to protect.
        return $lesson->teacher_id
            ? (bool) $ledger->withOwnerLock((int) $lesson->teacher_id, $apply)
            : (bool) DB::transaction(static function () use ($lesson, $apply) {
                Lesson::whereKey($lesson->id)->lockForUpdate()->first();

                return $apply();
            });
    }

    private static function ledger(): NarrationCreditLedger
    {
        return app(NarrationCreditLedger::class);
    }
}
