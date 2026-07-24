<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Console\Command;

/**
 * Publish lessons whose scheduled_publish_at has arrived. Run every minute by schedule:run
 * (routes/console.php). A lesson only goes live once ALL its scenes are ready — otherwise its
 * schedule is left in place so it can publish on the next tick once generation finishes.
 */
class PublishDueLessons extends Command
{
    protected $signature = 'lessons:publish-due';

    protected $description = 'Publish lessons whose scheduled publish time has passed';

    public function handle(): int
    {
        $due = Lesson::query()
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->where('status', '!=', LessonStatus::Published)
            ->get();

        $published = 0;
        foreach ($due as $lesson) {
            // Every scene must be ready — mirrors the manual publish guard.
            if ($lesson->scenes()->where('status', '!=', 'ready')->exists()) {
                $this->warn("Lesson {$lesson->id} due but not all scenes ready — leaving scheduled.");

                continue;
            }

            $lesson->update([
                'status' => LessonStatus::Published,
                'scheduled_publish_at' => null,
            ]);
            $published++;
            $this->info("Published lesson {$lesson->id} ({$lesson->title}).");
        }

        if ($published > 0) {
            $this->info("Published {$published} scheduled lesson(s).");
        }

        return self::SUCCESS;
    }
}
