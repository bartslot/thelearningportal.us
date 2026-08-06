<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Services\VideoTranscoder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Uploaded video becomes 720p H.264, and the original is never lost to a failure.
 *
 * Ron Slot's twenty-five second introduction arrived as 8.6 MB of 1080p, for a clip that plays in a
 * modal. Thirty children on one school wifi is the difference between a lesson starting and a
 * lesson buffering.
 *
 * The failure paths matter more than the happy one: a transcoder that eats somebody's only copy
 * because a codec was missing is worse than no transcoder at all.
 */
class VideoTranscoderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = storage_path('app/testing-video-'.bin2hex(random_bytes(4)));
        @mkdir($this->workDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->workDir);
        parent::tearDown();
    }

    private function requireFfmpeg(): void
    {
        if (! VideoTranscoder::make()->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }
    }

    /** A real, tiny clip at a given height, made by ffmpeg so the suite needs no binary fixture. */
    private function makeVideo(string $path, int $height, int $seconds = 1): void
    {
        $width = (int) round($height * 16 / 9 / 2) * 2;
        $p = new Process([
            config('services.ffmpeg.path', 'ffmpeg'), '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', "testsrc=size={$width}x{$height}:rate=24",
            '-f', 'lavfi', '-i', 'sine=frequency=440',
            '-t', (string) $seconds,
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-shortest', $path,
        ]);
        $p->setTimeout(120);
        $p->run();
    }

    public function test_a_1080p_upload_comes_out_720p_and_smaller(): void
    {
        $this->requireFfmpeg();
        $file = $this->workDir.'/tall.mp4';
        $this->makeVideo($file, 1080, 2);
        $before = filesize($file);

        $result = VideoTranscoder::make()->transcodeFile($file);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['skipped']);
        $this->assertLessThan($before, filesize($file), 'the transcode did not save anything');
        $this->assertSame(720, VideoTranscoder::make()->probe($file)['height']);
    }

    public function test_a_video_already_within_the_ceiling_is_left_byte_for_byte_alone(): void
    {
        // Re-encoding an already-fine file costs a generation of quality for nothing, and this is
        // what makes the backfill command safe to run repeatedly.
        $this->requireFfmpeg();
        $file = $this->workDir.'/fine.mp4';
        $this->makeVideo($file, 720);
        $hashBefore = md5_file($file);

        $result = VideoTranscoder::make()->transcodeFile($file);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
        $this->assertSame($hashBefore, md5_file($file), 'an already-compliant video was re-encoded');
    }

    public function test_a_portrait_phone_video_stays_portrait(): void
    {
        // scale=-2:720 must not rotate or letterbox a vertical clip into a landscape frame.
        $this->requireFfmpeg();
        $file = $this->workDir.'/portrait.mp4';
        $p = new Process([
            config('services.ffmpeg.path', 'ffmpeg'), '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'testsrc=size=1080x1920:rate=24', '-t', '1',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', $file,
        ]);
        $p->setTimeout(120);
        $p->run();

        VideoTranscoder::make()->transcodeFile($file);

        $probe = VideoTranscoder::make()->probe($file);
        $this->assertSame(720, $probe['height'], 'a portrait clip should be capped on its own height');
    }

    public function test_a_small_clip_is_never_blown_up(): void
    {
        $this->requireFfmpeg();
        $file = $this->workDir.'/small.mp4';
        $this->makeVideo($file, 360);

        VideoTranscoder::make()->transcodeFile($file);

        $this->assertSame(360, VideoTranscoder::make()->probe($file)['height'], '360p was upscaled');
    }

    public function test_the_original_survives_a_missing_ffmpeg(): void
    {
        // The whole point. No binary, no transcode, and the upload is still there.
        $file = $this->workDir.'/keepme.mp4';
        file_put_contents($file, 'not really a video, but it is the only copy');
        $before = md5_file($file);

        $result = (new VideoTranscoder('/nonexistent/ffmpeg', '/nonexistent/ffprobe'))->transcodeFile($file);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('ffmpeg not available', $result['reason']);
        $this->assertSame($before, md5_file($file), 'the original was destroyed');
    }

    public function test_the_original_survives_a_file_ffmpeg_cannot_read(): void
    {
        $this->requireFfmpeg();
        $file = $this->workDir.'/garbage.mp4';
        file_put_contents($file, str_repeat('nonsense', 100));
        $before = md5_file($file);

        $result = VideoTranscoder::make()->transcodeFile($file);

        $this->assertFalse($result['ok']);
        $this->assertSame($before, md5_file($file), 'an unreadable upload was destroyed');
        $this->assertFileDoesNotExist($file.'.transcoding.mp4', 'the scratch file was left behind');
    }

    public function test_probing_something_that_is_not_a_video_returns_null(): void
    {
        $this->requireFfmpeg();
        $file = $this->workDir.'/notvideo.txt';
        file_put_contents($file, 'hello');

        $this->assertNull(VideoTranscoder::make()->probe($file));
    }

    public function test_it_transcodes_a_file_on_a_storage_disk_in_place(): void
    {
        $this->requireFfmpeg();
        $disk = Storage::disk('public');
        $path = 'avatar-introductions/testing-'.bin2hex(random_bytes(3)).'/introduction.mp4';
        $disk->put($path, '');
        $this->makeVideo($disk->path($path), 1080, 2);

        $result = VideoTranscoder::make()->transcodeStored($path);

        $this->assertTrue($result['ok']);
        $this->assertGreaterThan($result['after'], $result['before']);
        $this->assertTrue($disk->exists($path), 'the stored file vanished');

        $disk->deleteDirectory(dirname($path));
    }
}
