<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Copy a whole lesson — scenes, quiz questions and its source row — onto a new owner.
 *
 * One copier, two callers: the guest sandbox (/try hands a visitor their own copy of the demo) and
 * the test harness (specs run against a fresh child of a real lesson instead of editing it). Both
 * want the same thing, and a second implementation would be the one that drifts.
 */
class LessonCopier
{
    /**
     * @param  array<string,mixed>  $overrides  columns to set on the copy after replication
     */
    public function copy(Lesson $source, User $owner, array $overrides = []): Lesson
    {
        return DB::transaction(function () use ($source, $owner, $overrides): Lesson {
            // lesson_code is excluded so the copy gets its own; game_pack_path because it points at
            // a generated PDF, and letting the copy regenerate over it would rewrite the original's.
            $lesson = $source->replicate(['lesson_code', 'game_pack_path']);
            $lesson->teacher_id = $owner->id;
            $lesson->scheduled_publish_at = null;
            $lesson->forceFill($overrides);
            $lesson->save();

            $source->scenes()->get()->each(function ($scene) use ($lesson): void {
                $copy = $scene->replicate();
                $copy->lesson_id = $lesson->id;
                $copy->save();
            });

            $source->quizQuestions()->get()->each(function ($question) use ($lesson): void {
                $copy = $question->replicate();
                $copy->lesson_id = $lesson->id;
                $copy->save();
            });

            // The wizard reads the source row for its "where did this come from" panel, and
            // Lesson::startGenerationPipeline() refuses to run without one.
            $sourceRow = $source->source;
            if ($sourceRow) {
                $copy = $sourceRow->replicate();
                $copy->lesson_id = $lesson->id;
                $copy->save();
            }

            return $lesson;
        });
    }
}
