<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Services\LessonCopier;
use Illuminate\Console\Command;

/**
 * A throwaway child of a real lesson, for the browser specs to edit.
 *
 * The specs drag waypoints, reorder itineraries and repaint fog — all of which WRITE. Pointing them
 * at a teacher's own lesson means a test run quietly rewrites their work (it did, once). This makes
 * a fresh copy of the parent on every run and hands back its id, so the parent stays the source of
 * truth and the child is disposable: the previous child is deleted first, so copies never pile up.
 */
class MakeTestLessonCopy extends Command
{
    protected $signature = 'lessons:test-copy {parent : id or lesson code of the lesson to copy}
                            {--json : print {"id":…,"code":…} for a test harness to read}';

    protected $description = 'Refresh the throwaway test copy of a lesson (deletes the previous one)';

    public function handle(LessonCopier $copier): int
    {
        $key = (string) $this->argument('parent');
        $parent = ctype_digit($key)
            ? Lesson::find((int) $key)
            : Lesson::where('lesson_code', strtoupper($key))->first();

        if (! $parent) {
            $this->error("No lesson matches [{$key}].");

            return self::FAILURE;
        }
        if ($parent->game_config['test_copy_of'] ?? null) {
            $this->error('That lesson is itself a test copy — pass the parent.');

            return self::FAILURE;
        }

        // Force-delete: a soft-deleted child would keep its scenes and its lesson code around, and
        // the next run would stack another copy behind it.
        Lesson::withTrashed()->where('game_config->test_copy_of', $parent->id)->get()
            ->each(fn (Lesson $old) => $old->forceDelete());

        $config = $parent->game_config ?? [];
        $config['test_copy_of'] = $parent->id;

        $child = $copier->copy($parent, $parent->teacher, [
            'title' => '[test] '.$parent->title,
            'game_config' => $config,
            // Published so the catalogue fixtures can find it, and the player can open it, without
            // anyone having to publish the parent.
            'status' => LessonStatus::Published,
            'wizard_step' => 4,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode(['id' => $child->id, 'code' => $child->lesson_code]));

            return self::SUCCESS;
        }

        $this->info("Test copy of #{$parent->id} → #{$child->id} ({$child->lesson_code})");

        return self::SUCCESS;
    }
}
