<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\ChapterNamer;
use Illuminate\Console\Command;

/**
 * Auto-name a lesson's chapters (Scene::chapter_name) from the narration.
 *
 *   php artisan lessons:name-chapters 5
 *   php artisan lessons:name-chapters 5 --force     # rename already-named scenes
 *   php artisan lessons:name-chapters --all         # every lesson with unnamed scenes
 */
final class NameChapters extends Command
{
    protected $signature = 'lessons:name-chapters
        {lesson? : lesson id}
        {--all : name chapters for every lesson}
        {--force : re-name scenes that already have a chapter name}';

    protected $description = 'Auto-derive short chapter names for a lesson\'s scenes from the narration.';

    public function handle(ChapterNamer $namer): int
    {
        $force = (bool) $this->option('force');

        $lessons = $this->option('all')
            ? Lesson::query()->whereHas('scenes')->get()
            : Lesson::whereKey($this->argument('lesson'))->get();

        if ($lessons->isEmpty()) {
            $this->error('No lesson matched. Pass a lesson id or --all.');

            return self::FAILURE;
        }

        $total = 0;
        foreach ($lessons as $lesson) {
            $named = $namer->nameLesson($lesson, $force);
            $total += $named;
            $this->line("  #{$lesson->id} {$lesson->title} — named {$named}");
        }

        $this->info("Named {$total} chapter(s) across ".$lessons->count().' lesson(s).');

        return self::SUCCESS;
    }
}
