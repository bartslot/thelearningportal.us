<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Models\Scene;
use App\Services\BackgroundImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Re-encode the backgrounds of lessons built before backgrounds were optimised on download.
 *
 * Those scenes hold whatever Wikimedia served — routinely 3840px and 1.7-3 MB each, so a sixteen
 * scene lesson is tens of megabytes before a word is spoken. Re-encoding them to stage-sized AVIF
 * measured 98x smaller across the eight heaviest in this install (73 MB down to 759 KB).
 *
 * THE ORIGINAL IS KEPT, renamed alongside as bg.original.<ext>. Re-encoding is lossy and some
 * backgrounds are teacher uploads with no source to re-fetch from, so the cheap insurance wins:
 * page weight is about what gets SERVED, and an unserved file on disk costs a visitor nothing.
 * --prune-originals removes them once you are happy.
 *
 * Dry run by default — it rewrites files and rows.
 */
class OptimiseSceneBackgrounds extends Command
{
    protected $signature = 'lessons:optimise-backgrounds
        {--apply : Actually re-encode. Without this the command only reports.}
        {--lesson= : Restrict to one lesson id or lesson_code.}
        {--prune-originals : Delete the kept bg.original.* files instead of leaving them.}';

    protected $description = 'Re-encode oversized scene backgrounds as stage-sized AVIF';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $disk = Storage::disk('public');
        $optimiser = BackgroundImageOptimizer::fromConfig();
        $cap = (int) config('services.imagery.max_bytes', 100_000);

        $scenes = Scene::query()
            ->whereNotNull('image_path')
            ->when($this->option('lesson'), function ($q, $ref) {
                $ids = Lesson::where('id', is_numeric($ref) ? (int) $ref : 0)
                    ->orWhere('lesson_code', $ref)->pluck('id');
                $q->whereIn('lesson_id', $ids);
            })
            ->orderBy('lesson_id')->orderBy('order')
            ->get();

        if ($scenes->isEmpty()) {
            $this->warn('No scenes with a background image matched.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? "Re-encoding {$scenes->count()} backgrounds (cap ".number_format($cap / 1024, 0).' KB)'
            : "DRY RUN over {$scenes->count()} backgrounds — pass --apply to write.");
        $this->newLine();

        $before = 0;
        $after = 0;
        $done = 0;
        $skipped = 0;
        $failed = 0;
        $grainless = 0;

        foreach ($scenes as $scene) {
            $path = (string) $scene->image_path;

            if (! $disk->exists($path)) {
                $this->line(sprintf('  <fg=yellow>missing</> scene %-6d %s', $scene->id, $path));
                $skipped++;

                continue;
            }

            $size = $disk->size($path);

            // Already small enough and in a modern format — nothing to gain, and re-encoding an
            // AVIF would only lose another generation of quality.
            if ($size <= $cap && preg_match('/\.(avif|webp)$/i', $path)) {
                $skipped++;

                continue;
            }

            if (! $apply) {
                $this->line(sprintf('  scene %-6d %8s KB  %s', $scene->id, number_format($size / 1024, 0), $path));
                $before += $size;
                $done++;

                continue;
            }

            try {
                $result = $optimiser->optimise($disk->get($path));
            } catch (Throwable $e) {
                $this->line(sprintf('  <fg=red>failed</> scene %-6d %s', $scene->id, $e->getMessage()));
                $failed++;

                continue;
            }

            $newPath = preg_replace('/\.[^.\/]+$/', '', $path).'.'.$result['extension'];

            // Keep the original unless it IS the path we are about to write (same extension), in
            // which case there is nothing to preserve — the bytes are being replaced in place.
            if ($newPath !== $path) {
                $keep = preg_replace('/\.([^.\/]+)$/', '.original.$1', $path);
                if (! $disk->exists($keep)) {
                    $disk->move($path, $keep);
                } else {
                    $disk->delete($path);
                }
            }

            $disk->put($newPath, $result['bytes']);
            $scene->update(['image_path' => $newPath]);

            if ($this->option('prune-originals')) {
                $keep = preg_replace('/\.([^.\/]+)$/', '.original.$1', $path);
                $disk->delete($keep);
            }

            $before += $size;
            $after += strlen($result['bytes']);
            $done++;
            $result['grain'] or $grainless++;

            $this->line(sprintf(
                '  scene %-6d %8s KB -> %6s KB  %s q%d%s',
                $scene->id,
                number_format($size / 1024, 0),
                number_format(strlen($result['bytes']) / 1024, 0),
                $result['extension'],
                $result['quality'],
                $result['grain'] ? ' +grain' : '',
            ));
        }

        $this->newLine();

        if ($apply) {
            $this->info(sprintf(
                '%d re-encoded, %d skipped, %d failed — %s MB down to %s KB.',
                $done, $skipped, $failed,
                number_format($before / 1048576, 1),
                number_format($after / 1024, 0),
            ));

            if (! $this->option('prune-originals') && $done > 0) {
                $this->line('Originals kept as bg.original.* — rerun with --prune-originals to remove them.');
            }

            // Say it out loud rather than leaving it in a per-line suffix nobody reads. On SiteGround
            // every single result comes back grainless — there is no avifenc, no compiler and no AV1
            // encoder in its ffmpeg — and the difference is a Turner sky compressed to flat plateaus.
            // The fix is to re-encode from the kept originals on a machine that has the encoder.
            if ($grainless > 0) {
                $this->newLine();
                $this->warn("{$grainless} of {$done} were written WITHOUT film grain — this host has no AVIF encoder that can synthesise it.");
                $this->line('  Skies and shadows will band. Re-encode from the kept originals somewhere that can:');
                $this->line('  <fg=cyan>scripts/regrain-prod-backgrounds.sh</>');
            }
        } else {
            $this->info(sprintf(
                '%d would be re-encoded (%s MB today), %d already fine.',
                $done, number_format($before / 1048576, 1), $skipped,
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
