<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Turn an uploaded video into something a classroom can stream.
 *
 * Video arrives at whatever the camera or phone produced. Ron Slot's twenty-five second
 * introduction was 8.6 MB of 1080p — a broadcast master, for a clip that plays in a modal. Thirty
 * children on one school wifi pulling that is the difference between a lesson starting and a lesson
 * buffering, and it is the same problem BackgroundImageOptimizer already solves for stills.
 *
 * So: 720p, H.264 High, AAC, and the moov atom moved to the front so playback can start before the
 * file has finished arriving. Measured on that introduction: 8.6 MB → 2.7 MB, no visible loss at
 * the size it is actually displayed.
 *
 * THE ORIGINAL IS NEVER LOST WITHOUT A REPLACEMENT. Every failure path — ffmpeg missing, ffmpeg
 * erroring, a timeout, an output somehow larger than the input — leaves the stored file exactly as
 * it was and returns a result saying why. A transcoder that eats the only copy of someone's video
 * because a codec was unavailable is worse than no transcoder.
 *
 * Idempotent: a file already inside the ceiling is left alone, so re-running over a library does
 * not re-encode it and lose a generation each time.
 */
class VideoTranscoder
{
    /** The tallest we ship. 720p is the point where a talking head stops looking better. */
    private const MAX_HEIGHT = 720;

    /**
     * Constant Rate Factor. 23 is ffmpeg's default and visually transparent for this material;
     * higher numbers are smaller and softer. 25 was measured as the point where Ron's beard starts
     * to smear on a projector.
     */
    private const CRF = 23;

    /** Long enough for a ten-minute clip on shared hosting, short enough to not hang a request. */
    private const TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly string $ffmpegPath = 'ffmpeg',
        private readonly string $ffprobePath = 'ffprobe',
    ) {}

    public static function make(): self
    {
        return new self(
            (string) config('services.ffmpeg.path', 'ffmpeg'),
            (string) config('services.ffmpeg.probe_path', 'ffprobe'),
        );
    }

    /**
     * Transcode a video already stored on a disk, replacing it in place.
     *
     * @param  string  $path  path on the disk, e.g. "avatar-introductions/ron-slot/introduction.mp4"
     * @return array{ok: bool, skipped: bool, reason: string, before: int, after: int}
     */
    public function transcodeStored(string $path, string $disk = 'public'): array
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return $this->result(false, false, 'file not found', 0, 0);
        }

        $before = (int) $storage->size($path);
        $absolute = $storage->path($path);

        $outcome = $this->transcodeFile($absolute);

        if ($outcome['ok'] && ! $outcome['skipped']) {
            Log::info('[VideoTranscoder] transcoded', [
                'path' => $path,
                'before' => $before,
                'after' => $outcome['after'],
                'saved_pct' => $before > 0 ? (int) round(100 - ($outcome['after'] / $before * 100)) : 0,
            ]);
        }

        return ['before' => $before] + $outcome;
    }

    /**
     * Transcode a file on the local filesystem, in place.
     *
     * @return array{ok: bool, skipped: bool, reason: string, before: int, after: int}
     */
    public function transcodeFile(string $absolutePath): array
    {
        $before = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;

        if (! $this->isAvailable()) {
            // Not an error the uploader can do anything about, and not a reason to lose the file.
            Log::warning('[VideoTranscoder] ffmpeg not available — storing the original', ['path' => $absolutePath]);

            return $this->result(false, true, 'ffmpeg not available', $before, $before);
        }

        if ($this->alreadyWithinCeiling($absolutePath)) {
            return $this->result(true, true, 'already 720p H.264', $before, $before);
        }

        // Encode beside the original, never over it: ffmpeg writing to its own input truncates the
        // file the moment it opens, so a crash halfway through would leave nothing to fall back to.
        $temp = $absolutePath.'.transcoding.mp4';

        try {
            $process = new Process([
                $this->ffmpegPath, '-y', '-loglevel', 'error',
                '-i', $absolutePath,
                // Scale to 720p tall, keep the aspect, and round the width to an even number —
                // H.264 chroma subsampling cannot encode an odd dimension. Portrait phone video
                // stays portrait; `min()` means a clip already smaller is never blown UP.
                '-vf', 'scale=-2:min('.self::MAX_HEIGHT.'\,ih)',
                '-c:v', 'libx264', '-crf', (string) self::CRF, '-preset', 'medium',
                '-profile:v', 'high', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
                // Playback can start before the download finishes. Without this a browser waits for
                // the whole file just to find the index at the end of it.
                '-movflags', '+faststart',
                $temp,
            ]);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($temp) || filesize($temp) === 0) {
                @unlink($temp);
                Log::warning('[VideoTranscoder] ffmpeg failed — keeping the original', [
                    'path' => $absolutePath,
                    'stderr' => mb_substr($process->getErrorOutput(), -400),
                ]);

                return $this->result(false, true, 'ffmpeg failed', $before, $before);
            }

            $after = (int) filesize($temp);

            // An already-efficient file can come out BIGGER. Keeping that would be a re-encode that
            // costs quality and bytes at once, which is the worst of both.
            if ($before > 0 && $after >= $before) {
                @unlink($temp);

                return $this->result(true, true, 'original is already smaller', $before, $before);
            }

            rename($temp, $absolutePath);

            return $this->result(true, false, 'transcoded to 720p H.264', $before, $after);
        } catch (ProcessTimedOutException) {
            @unlink($temp);
            Log::warning('[VideoTranscoder] timed out — keeping the original', ['path' => $absolutePath]);

            return $this->result(false, true, 'timed out', $before, $before);
        } catch (\Throwable $e) {
            @unlink($temp);
            Log::warning('[VideoTranscoder] failed — keeping the original', [
                'path' => $absolutePath, 'error' => $e->getMessage(),
            ]);

            return $this->result(false, true, $e->getMessage(), $before, $before);
        }
    }

    /**
     * Already 720p or shorter AND already H.264, so re-encoding would only cost a generation.
     *
     * Deliberately does not check the bitrate: a 720p H.264 file that is merely large is still a
     * file every browser plays, and the quality lost by squeezing it again is not worth the bytes.
     */
    private function alreadyWithinCeiling(string $absolutePath): bool
    {
        $probe = $this->probe($absolutePath);

        return $probe !== null
            && $probe['height'] > 0
            && $probe['height'] <= self::MAX_HEIGHT
            && $probe['codec'] === 'h264';
    }

    /** @return array{height: int, codec: string}|null */
    public function probe(string $absolutePath): ?array
    {
        try {
            $process = new Process([
                $this->ffprobePath, '-v', 'error',
                '-select_streams', 'v:0',
                '-show_entries', 'stream=height,codec_name',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $absolutePath,
            ]);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput())), 'strlen'));

            // ffprobe prints the entries in the order they were asked for: codec_name, then height.
            return count($lines) >= 2
                ? ['codec' => $lines[0], 'height' => (int) $lines[1]]
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isAvailable(): bool
    {
        try {
            $process = new Process([$this->ffmpegPath, '-version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{ok: bool, skipped: bool, reason: string, before: int, after: int} */
    private function result(bool $ok, bool $skipped, string $reason, int $before, int $after): array
    {
        return compact('ok', 'skipped', 'reason', 'before', 'after');
    }
}
