<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\VideoTranscoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Bring every video already on the disk down to 720p H.264.
 *
 * New uploads are transcoded as they arrive (VideoTranscoder, wired into the upload paths). This is
 * for everything that landed before that existed, and for a server that has just had ffmpeg
 * installed and needs to catch up on the files it stored untouched.
 *
 * Idempotent: a file already inside the ceiling is skipped, so this is safe to run on a schedule or
 * after every deploy without slowly re-encoding the library into mush.
 */
class TranscodeVideos extends Command
{
    protected $signature = 'media:transcode-videos
        {--disk=public : Storage disk to walk}
        {--dry-run : Report what would change without touching anything}';

    protected $description = 'Re-encode stored videos to 720p H.264 (skips files already within the ceiling)';

    /** Where videos live. Narrator introductions today; lesson video uploads land here too. */
    private const DIRECTORIES = ['avatar-introductions', 'avatars', 'lessons'];

    private const EXTENSIONS = ['mp4', 'mov', 'webm', 'm4v'];

    public function handle(): int
    {
        $transcoder = VideoTranscoder::make();

        if (! $transcoder->isAvailable()) {
            $this->error('ffmpeg is not available on this machine — nothing to do.');
            $this->line('Set FFMPEG_PATH if it is installed somewhere unusual.');

            return self::FAILURE;
        }

        $disk = (string) $this->option('disk');
        $storage = Storage::disk($disk);
        $dryRun = (bool) $this->option('dry-run');

        $videos = [];
        foreach (self::DIRECTORIES as $dir) {
            if (! $storage->directoryExists($dir)) {
                continue;
            }
            foreach ($storage->allFiles($dir) as $file) {
                if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    $videos[] = $file;
                }
            }
        }

        if ($videos === []) {
            $this->info('No videos found.');

            return self::SUCCESS;
        }

        $this->info(count($videos).' video(s) found.');
        $savedBytes = 0;
        $changed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($videos as $path) {
            if ($dryRun) {
                $probe = $transcoder->probe($storage->path($path));
                $note = $probe ? "{$probe['codec']} {$probe['height']}p" : 'unreadable';
                $this->line(sprintf('  %-64s %s', mb_strimwidth($path, 0, 64, '…'), $note));

                continue;
            }

            $result = $transcoder->transcodeStored($path, $disk);

            if (! $result['ok']) {
                $failed++;
                $this->warn(sprintf('  ! %-58s %s', mb_strimwidth($path, 0, 58, '…'), $result['reason']));

                continue;
            }

            if ($result['skipped']) {
                $skipped++;

                continue;
            }

            $changed++;
            $savedBytes += $result['before'] - $result['after'];
            $this->line(sprintf(
                '  ✓ %-58s %s → %s',
                mb_strimwidth($path, 0, 58, '…'),
                $this->human($result['before']),
                $this->human($result['after']),
            ));
        }

        if ($dryRun) {
            $this->info('Dry run — nothing changed.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            '%d transcoded, %d already fine, %d failed — %s saved.',
            $changed, $skipped, $failed, $this->human($savedBytes),
        ));

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024).' KB';
    }
}
