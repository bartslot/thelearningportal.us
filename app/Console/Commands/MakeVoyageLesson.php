<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\VoyageLessonBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Create/refresh ANY voyage lesson from the catalog, e.g.:
 *   php artisan lessons:make-voyage columbus-1492
 * The voyage must have `legs` (and ideally a `fleet`) in resources/js/timemap/voyages.json, plus an
 * optional <id>-tour.json supplying per-leg place/date/story/images. Idempotent — rebuilds the
 * lesson's scenes on re-run. (The Abel Tasman demo keeps its own command for its curated imagery.)
 */
class MakeVoyageLesson extends Command
{
    protected $signature = 'lessons:make-voyage {voyage : Voyage id from voyages.json (e.g. columbus-1492)}
        {--teacher= : Teacher email (default: first teacher)}
        {--title= : Override the lesson title}';

    protected $description = 'Create/refresh a voyage lesson (game_type=voyage) from the voyage catalog';

    public function handle(VoyageLessonBuilder $builder): int
    {
        $voyageId = (string) $this->argument('voyage');

        $teacher = $this->option('teacher')
            ? User::where('email', $this->option('teacher'))->first()
            : User::where('role', 'teacher')->orderBy('id')->first();
        if (! $teacher) {
            $this->error('No teacher account found.');

            return self::FAILURE;
        }

        try {
            $overrides = $this->option('title') ? ['title' => (string) $this->option('title')] : [];
            $this->line("Building voyage lesson '{$voyageId}' (hosting remote images on Cloudinary — may take a moment)…");
            $lesson = $builder->build($voyageId, $teacher, $overrides);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Lesson '{$lesson->title}' ready — code {$lesson->lesson_code}, {$lesson->scenes()->count()} scenes.");
        $this->line("Play: /lesson/{$lesson->lesson_code}");

        return self::SUCCESS;
    }
}
