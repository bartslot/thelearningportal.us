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
    /** Rows the signed-in user may work with: their own, or all of them for an admin. */
    public function scopeOwnedByCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->isAdmin()) {
            return $query;
        }

        // No user means no rows — never fall through to an unscoped list.
        return $query->where($this->getTable().'.teacher_id', $user?->id);
    }
}
