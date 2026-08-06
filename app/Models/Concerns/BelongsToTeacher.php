<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * For records owned by a teacher through a `teacher_id` column.
 *
 * Every teacher-facing list filters through ownedByCurrentUser() instead of
 * hand-written `where('teacher_id', auth()->id())`, so the one place that
 * decides "whose rows are these" also grants admins the whole platform.
 */
trait BelongsToTeacher
{
    /**
     * Rows the signed-in user may CHANGE: their own, or all of them for an admin.
     *
     * This is the write scope, and it deliberately does not know about public lessons. Every
     * `findOrFail()` before an edit, a delete, a publish or a rename goes through here, so widening
     * it would have handed every teacher an edit button on everybody else's work. Reading is a
     * different question with a different answer — see visibleToCurrentUser().
     */
    public function scopeOwnedByCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->isAdmin()) {
            return $query;
        }

        // No user means no rows — never fall through to an unscoped list.
        return $query->where($this->getTable().'.teacher_id', $user?->id);
    }

    /**
     * Rows the signed-in user may SEE: their own, plus anything shared with every teacher.
     *
     * Only models with an `is_public` column can be shared; for anything else this is the same as
     * ownedByCurrentUser(). Classrooms use this trait too, and a classroom carries pupils' names and
     * results — it has no is_public column and must never grow one by accident.
     *
     * Guest-owned rows are excluded whatever their flag says. The /try sandbox hands anonymous
     * visitors a throwaway account and a copy of the demo, and those copies would otherwise fill
     * every real teacher's library with other people's abandoned experiments.
     */
    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->isAdmin()) {
            return $query;
        }

        $table = $this->getTable();

        if (! $this->sharesPublicly()) {
            return $query->where($table.'.teacher_id', $user?->id);
        }

        return $query->where(function (Builder $q) use ($table, $user) {
            $q->where($table.'.teacher_id', $user?->id)
                ->orWhere(fn (Builder $shared) => $shared
                    ->where($table.'.is_public', true)
                    ->whereHas('teacher', fn (Builder $owner) => $owner->where('is_guest_demo', false)));
        });
    }

    /** Whether this model has an is_public column to share by. */
    private function sharesPublicly(): bool
    {
        return in_array('is_public', $this->getFillable(), true);
    }
}
