<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Lesson;
use App\Models\User;

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
 * THE PAID TIER IS NOT BUILT YET. `creditCharacters()` returns 0 for everyone until the Stripe
 * credit ledger lands; when it does, only that one method changes and every caller keeps working.
 * Nothing else in the app should ask "can this teacher afford it" — ask here.
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
     * Characters a teacher has bought and not yet spent.
     *
     * Stub. €5 buys 5000 characters, spendable across any of their lessons — see the Stripe credit
     * ledger when it exists. Returning 0 means every teacher is on the free allowance today, which
     * is the safe direction to be wrong in.
     */
    public static function creditCharacters(?User $teacher): int
    {
        return 0;
    }

    /** Everything this lesson may still spend: its free allowance plus the teacher's credits. */
    public static function allowanceFor(Lesson $lesson): int
    {
        return self::FREE_CHARACTERS_PER_LESSON + self::creditCharacters($lesson->teacher);
    }

    /** Edited characters already charged to this lesson. */
    public static function spentOn(Lesson $lesson): int
    {
        return max(0, (int) $lesson->narration_edit_characters);
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

    /** Whether this edit fits in what the lesson has left. */
    public static function canAfford(Lesson $lesson, string $before, string $after): bool
    {
        return self::costOfEdit($before, $after) <= self::remainingFor($lesson);
    }

    /**
     * Charge an edit to the lesson. Call ONLY after the edit is actually saved.
     *
     * Uses a raw increment rather than read-modify-write: two panels autosaving the same scene at
     * once would otherwise each read the same total and one charge would vanish.
     */
    public static function charge(Lesson $lesson, int $characters): void
    {
        if ($characters > 0) {
            $lesson->increment('narration_edit_characters', $characters);
        }
    }
}
