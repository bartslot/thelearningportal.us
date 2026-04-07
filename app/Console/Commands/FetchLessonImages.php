<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\ImageSearchService;
use Illuminate\Console\Command;

/**
 * Backfills slideshow images for lessons that don't have them yet.
 *
 * Usage:
 *   php artisan lessons:fetch-images          # all lessons missing images
 *   php artisan lessons:fetch-images --id=42  # one specific lesson
 *   php artisan lessons:fetch-images --force  # re-fetch even if images exist
 */
class FetchLessonImages extends Command
{
    protected $signature = 'lessons:fetch-images
                            {--id= : Only process a single lesson by ID}
                            {--force : Re-fetch images even for lessons that already have them}
                            {--limit=12 : Number of images to fetch per lesson}';

    protected $description = 'Fetch Europeana / Wikimedia background images for lessons';

    public function handle(ImageSearchService $imageSearch): int
    {
        $limit  = (int) $this->option('limit');
        $force  = (bool) $this->option('force');
        $onlyId = $this->option('id');

        $query = Lesson::query();

        if ($onlyId) {
            $query->where('id', (int) $onlyId);
        } elseif (! $force) {
            $query->whereNull('slideshow_images');
        }

        $lessons = $query->get();

        if ($lessons->isEmpty()) {
            $this->info('No lessons to process.');
            return self::SUCCESS;
        }

        $this->info("Processing {$lessons->count()} lesson(s)…");
        $bar = $this->output->createProgressBar($lessons->count());
        $bar->start();

        $succeeded = 0;
        $failed    = 0;

        foreach ($lessons as $lesson) {
            try {
                $images = $imageSearch->fetchImages($lesson->topic, $limit);

                if (! empty($images)) {
                    $lesson->update(['slideshow_images' => $images]);
                    $this->line(''); // newline after progress bar
                    $this->line(
                        "  <fg=green>✓</> Lesson #{$lesson->id} — {$lesson->title}: " . count($images) . " images"
                    );
                    $succeeded++;
                } else {
                    $this->line('');
                    $this->line("  <fg=yellow>–</> Lesson #{$lesson->id} — {$lesson->title}: no images found");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->line('');
                $this->line("  <fg=red>✗</> Lesson #{$lesson->id} — {$lesson->title}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done — {$succeeded} succeeded, {$failed} skipped/failed.");

        return self::SUCCESS;
    }
}
