<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\User;
use RuntimeException;

/**
 * Sharing a lesson with every teacher, and taking a copy of one somebody else shared.
 *
 * The shared shelf works by copying, not by co-editing. A public lesson stays owned and edited by
 * whoever wrote it; a teacher who wants it different for their class takes their own copy and
 * changes that. Nobody can overwrite anyone else's work, no lesson needs a lock, and "who may edit
 * this" stays the same question it has always been.
 *
 * The copy itself is LessonCopier's job — the same deep copy the guest sandbox and the test harness
 * use. This class is only the rules around it.
 */
class LessonSharing
{
    public function __construct(private readonly LessonCopier $copier) {}

    /**
     * Share a lesson, or stop sharing it.
     *
     * Only the owner (or an admin) decides. Guest sandbox accounts cannot share at all: their
     * lessons are throwaway copies pruned after 24 hours, and publishing them would fill every real
     * teacher's library with abandoned experiments and leave dead links behind.
     */
    public function setPublic(Lesson $lesson, User $actor, bool $public): Lesson
    {
        if (! $this->mayShare($lesson, $actor)) {
            throw new RuntimeException('Only the teacher who wrote a lesson can share it.');
        }

        $lesson->update(['is_public' => $public]);

        return $lesson;
    }

    public function mayShare(Lesson $lesson, ?User $actor): bool
    {
        if (! $actor || $actor->isGuestDemo()) {
            return false;
        }

        return $actor->isAdmin() || $lesson->teacher_id === $actor->id;
    }

    /**
     * Take a copy of a lesson into someone's own library.
     *
     * Allowed for any lesson they can SEE — their own (duplicating is useful in its own right) or
     * one shared with everybody. Never for a lesson that is neither, which is what stops a guessed
     * id from copying a stranger's private draft.
     *
     * The copy always starts private and unpublished. Inheriting `is_public` would re-share
     * somebody's edit of your lesson under their name without either of you choosing it, and
     * inheriting `published` would put an unreviewed copy in front of a class.
     */
    public function copyToLibrary(Lesson $source, User $owner): Lesson
    {
        if (! $this->mayCopy($source, $owner)) {
            throw new RuntimeException('That lesson is not shared with you.');
        }

        return $this->copier->copy($source, $owner, [
            'is_public' => false,
            'status' => \App\Enums\LessonStatus::Configuring,
            // Named for the person who now owns it, so a library of copies is not five rows with
            // the same title. The original keeps its own name untouched.
            'title' => $this->copyTitle($source, $owner),
        ]);
    }

    public function mayCopy(Lesson $lesson, ?User $actor): bool
    {
        if (! $actor) {
            return false;
        }

        return $actor->isAdmin()
            || $lesson->teacher_id === $actor->id
            || ($lesson->is_public && ! $lesson->teacher?->isGuestDemo());
    }

    /**
     * "The Silk Road" → "The Silk Road (copy)", then "(copy 2)", "(copy 3)".
     *
     * Counted against what this teacher already has, so two teachers copying the same lesson both
     * get a clean "(copy)" rather than one of them inheriting the other's numbering.
     */
    private function copyTitle(Lesson $source, User $owner): string
    {
        $base = (string) $source->title;
        $title = __(':title (copy)', ['title' => $base]);

        $taken = Lesson::query()
            ->where('teacher_id', $owner->id)
            ->where('title', 'like', $base.'%')
            ->pluck('title')
            ->all();

        for ($n = 2; in_array($title, $taken, true); $n++) {
            $title = __(':title (copy :n)', ['title' => $base, 'n' => $n]);
        }

        return $title;
    }
}
