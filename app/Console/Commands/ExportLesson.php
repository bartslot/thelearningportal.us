<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\LessonExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Write a lesson back out as an authored spec, so it can be committed and rebuilt elsewhere.
 *
 *   php artisan lessons:export 18                    # by id
 *   php artisan lessons:export J5PRQI                # by lesson code
 *   php artisan lessons:export 18 --name=tasman      # choose the filename
 *   php artisan lessons:export 18 --stdout           # print instead of writing
 *
 * The output belongs in git. Deploying it is the existing path: rsync resources/lessons to the
 * server, then `php artisan lessons:compose <name>` there.
 */
class ExportLesson extends Command
{
    protected $signature = 'lessons:export
        {lesson : Lesson id or lesson code}
        {--name= : Spec filename without .php (default: a slug of the title)}
        {--stdout : Print the spec instead of writing a file}
        {--force : Overwrite an existing spec}';

    protected $description = 'Export a lesson to a resources/lessons spec that lessons:compose can rebuild';

    public function handle(LessonExporter $exporter): int
    {
        $key = (string) $this->argument('lesson');

        $lesson = ctype_digit($key)
            ? Lesson::find((int) $key)
            : Lesson::where('lesson_code', $key)->first();

        if (! $lesson) {
            $this->error("No lesson matching '{$key}'.");

            return self::FAILURE;
        }

        $spec = $exporter->toSpec($lesson);
        $sceneCount = count($spec['scenes'] ?? []);

        if ($sceneCount === 0) {
            $this->error("'{$lesson->title}' has no scenes to export.");

            return self::FAILURE;
        }

        $php = $exporter->toPhp($spec, sprintf(
            '%s — exported from lesson %s (%d scenes).',
            $lesson->title,
            $lesson->lesson_code,
            $sceneCount,
        ));

        if ($this->option('stdout')) {
            $this->line($php);

            return self::SUCCESS;
        }

        $name = (string) ($this->option('name') ?: Str::slug((string) $lesson->title));
        $path = resource_path("lessons/{$name}.php");

        if (File::exists($path) && ! $this->option('force')) {
            $this->error("{$path} already exists — pass --force to overwrite.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $php);

        $this->info("✓ {$lesson->title} → resources/lessons/{$name}.php ({$sceneCount} scenes)");

        $this->reportUnresolvedImages($exporter);

        $this->newLine();
        $this->line('Rebuild it anywhere with:');
        $this->line("  php artisan lessons:compose {$name}");

        return self::SUCCESS;
    }

    /**
     * Backgrounds we only know as a path in our own storage cannot be re-fetched on the server, so
     * say which ones rather than shipping a spec with silent holes in it.
     */
    private function reportUnresolvedImages(LessonExporter $exporter): void
    {
        $unresolved = $exporter->unresolvedImages();
        if ($unresolved === []) {
            return;
        }

        $this->newLine();
        $this->warn(count($unresolved).' image(s) have no source URL and will be missing after a rebuild:');
        foreach ($unresolved as $line) {
            $this->line("  - {$line}");
        }
        $this->line('  Re-source them in the wizard, or upload them to Cloudinary, then export again.');
    }
}
