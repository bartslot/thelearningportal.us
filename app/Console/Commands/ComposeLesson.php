<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LessonComposer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Build classroom lessons from the authored specs in resources/lessons/*.php.
 *
 *   php artisan lessons:compose                 # every spec
 *   php artisan lessons:compose anne-frank      # one spec
 *   php artisan lessons:compose --no-narrate    # skip TTS (fast structural rebuild)
 *
 * Idempotent: a spec's `key` identifies its lesson, and each run rebuilds that lesson's scenes.
 */
class ComposeLesson extends Command
{
    protected $signature = 'lessons:compose
        {spec?* : Spec name(s) from resources/lessons (default: all)}
        {--teacher= : Teacher email (default: the wizard teacher, id 2, else the first teacher)}
        {--no-narrate : Skip Azure narration}
        {--no-publish : Build but leave unpublished}';

    protected $description = 'Compose full lessons (story, gallery, map, 3D, video, quizzes) from authored specs';

    public function handle(LessonComposer $composer): int
    {
        $teacher = $this->option('teacher')
            ? User::where('email', $this->option('teacher'))->first()
            : (User::find(2) ?? User::where('role', 'teacher')->orderBy('id')->first());

        if (! $teacher) {
            $this->error('No teacher account found.');

            return self::FAILURE;
        }

        $dir = resource_path('lessons');
        if (! File::isDirectory($dir)) {
            $this->error("No spec directory at {$dir}");

            return self::FAILURE;
        }

        $wanted = (array) $this->argument('spec');
        $files = collect(File::files($dir))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->filter(fn ($f) => $wanted === [] || in_array($f->getFilenameWithoutExtension(), $wanted, true))
            ->values();

        if ($files->isEmpty()) {
            $this->error('No matching specs found in resources/lessons.');

            return self::FAILURE;
        }

        $composer->onProgress(fn (string $m) => $this->line($m));
        $built = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                $spec = require $file->getPathname();
                if (! is_array($spec)) {
                    $this->warn("  {$file->getFilename()} did not return an array — skipped");

                    continue;
                }

                $lesson = $composer->build($spec, $teacher, ! $this->option('no-narrate'));

                if (! $this->option('no-publish')) {
                    $composer->publish($lesson);
                }

                $lesson->refresh();
                $this->info(sprintf(
                    '  ✓ %s — %d scenes, %d narrated, %d images, status=%s  /lesson/%s',
                    $lesson->title,
                    $lesson->scenes()->count(),
                    $lesson->scenes()->whereNotNull('audio_path')->count(),
                    $lesson->scenes()->whereNotNull('image_path')->count(),
                    $lesson->status->value,
                    $lesson->lesson_code,
                ));
                $built++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("  ✗ {$file->getFilename()}: ".$e->getMessage());
                report($e);
            }
        }

        $this->newLine();
        $this->info("Composed {$built} lesson(s)".($failed ? ", {$failed} failed" : '').'.');
        $this->line('Tip: run `php artisan lessons:enhance-images` to sharpen every background.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
