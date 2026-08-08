<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackgroundImageOptimizer;
use Illuminate\Console\Command;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Re-encode a directory of kept `*.original.*` files, on a machine that can write film grain.
 *
 * Production cannot. SiteGround has no `avifenc`, no compiler and no AV1 encoder in its ffmpeg, so
 * `lessons:optimise-backgrounds --apply` there falls through to GD's AVIF encoder and every result
 * comes out `no-grain`. That is not a small loss: film grain is most of why these backgrounds are
 * AVIF at all. The decoder synthesises the grain from ~100 bytes of parameters, which means the
 * encoder can compress the smooth image underneath much harder and still avoid the flat plateaus
 * that heavy compression leaves in skies and shadows. Without it, a Turner sky at 90 KB is a
 * gradient with the brushwork wiped off it.
 *
 * So the encoding happens where the tool exists — a developer machine — and the result is synced
 * up. `lessons:optimise-backgrounds --apply` keeps every original beside its replacement precisely
 * so this is possible after the fact, with no second generation of loss: this reads the ORIGINAL,
 * never the compressed file standing in for it.
 *
 * Run via scripts/regrain-prod-backgrounds.sh, which does the rsync either side of it.
 */
class EncodeKeptOriginals extends Command
{
    protected $signature = 'lessons:encode-originals
        {dir : Directory tree holding *.original.* files, laid out as it is on the server}
        {--apply : Actually write. Without this the command only reports.}';

    protected $description = 'Re-encode kept *.original.* files with film grain, for syncing to a host that cannot';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/');
        $apply = (bool) $this->option('apply');

        if (! is_dir($dir)) {
            $this->error("Not a directory: {$dir}");

            return self::FAILURE;
        }

        $optimiser = BackgroundImageOptimizer::fromConfig();

        // Preflight, before touching a hundred megabytes: if THIS machine cannot write grain either,
        // the whole exercise produces files no better than the ones already live. Fail loudly rather
        // than spend twenty minutes making an identical copy.
        if (! $this->canWriteGrain($optimiser)) {
            $this->error('This machine cannot write AV1 film grain.');
            $this->line('  Needs avifenc built against AOM. On macOS: brew install libavif');
            $this->line('  Check with: avifenc --version   (must mention "aom")');

            return self::FAILURE;
        }

        // Any basename, not just `bg`. Backgrounds are the common case but the same rename guards
        // skyboxes, shot grids and hero images — matching only `bg.original.*` would have quietly
        // regrained 47 of the 78 files on production and reported success.
        $files = iterator_to_array(
            Finder::create()->files()->in($dir)->name('/^(?<stem>.+)\.original\.[a-z0-9]+$/i')->sortByName(),
            false,
        );

        if ($files === []) {
            $this->warn("No *.original.* files under {$dir}.");

            return self::SUCCESS;
        }

        $this->line($apply
            ? 'Re-encoding '.count($files).' originals with film grain'
            : 'DRY RUN over '.count($files).' originals — pass --apply to write.');
        $this->newLine();

        $before = 0;
        $after = 0;
        $written = 0;
        $failed = 0;

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $source = $file->getPathname();
            $size = (int) filesize($source);
            $stem = (string) preg_replace('/\.original\.[^.]+$/i', '', $file->getFilename());
            $label = ltrim(str_replace($dir, '', $file->getPath()), '/').'/'.$stem;

            try {
                $result = $optimiser->optimise((string) file_get_contents($source));
            } catch (Throwable $e) {
                $this->line(sprintf('  <fg=red>failed</> %-46s %s', $label, $e->getMessage()));
                $failed++;

                continue;
            }

            // A result with no grain is the exact thing this command exists to avoid. It should be
            // unreachable after the preflight, but shipping one silently would replace a live file
            // with something no better, so it stops instead.
            if (! $result['grain']) {
                $this->line(sprintf('  <fg=red>no grain</> %-46s refusing to write', $label));
                $failed++;

                continue;
            }

            $before += $size;
            $after += strlen($result['bytes']);
            $this->line(sprintf(
                '  %-46s %8s KB -> %6s KB  %s q%d +grain',
                $label,
                number_format($size / 1024, 0),
                number_format(strlen($result['bytes']) / 1024, 0),
                $result['extension'],
                $result['quality'],
            ));

            if (! $apply) {
                continue;
            }

            file_put_contents($file->getPath().'/'.$stem.'.'.$result['extension'], $result['bytes']);
            $written++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d: %s MB -> %s MB. Failed %d.',
            $apply ? 'Wrote' : 'Would write',
            $apply ? $written : count($files) - $failed,
            number_format($before / 1048576, 1),
            number_format($after / 1048576, 1),
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Can this machine actually synthesise grain?
     *
     * Asked by encoding a real (tiny) image rather than by shelling out to `avifenc --version`
     * ourselves: the optimiser owns that decision, including which encoder rung it lands on, and a
     * second opinion here could disagree with the one that matters.
     */
    private function canWriteGrain(BackgroundImageOptimizer $optimiser): bool
    {
        $probe = imagecreatetruecolor(64, 64);
        imagefilledrectangle($probe, 0, 0, 63, 63, imagecolorallocate($probe, 120, 90, 40));
        ob_start();
        imagejpeg($probe, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($probe);

        try {
            return $optimiser->optimise($bytes)['grain'] === true;
        } catch (Throwable) {
            return false;
        }
    }
}
