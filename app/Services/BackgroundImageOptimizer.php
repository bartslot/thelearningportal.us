<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Imaging\CanvasFactory;
use App\Services\Imaging\ImageCanvas;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Turn a downloaded painting or photograph into a scene backdrop a classroom can actually load.
 *
 * Sourced originals arrive at whatever size Wikimedia serves — routinely 3840px and 1.7-3 MB. A
 * sixteen-scene lesson built from those is tens of megabytes over a school's wifi, so every
 * background is resized to Full HD and squeezed under a hard byte cap before it is stored.
 *
 * AVIF, not WebP. Measured on a real lesson background at 1920px wide: AVIF reached the 100 KB
 * budget at a quality WebP needed more than twice the bytes to match.
 *
 * The film grain is the interesting part. It is NOT painted into the pixels — avifenc writes ~100
 * bytes of AV1 film-grain parameters into the bitstream and the DECODER synthesises the grain as it
 * draws. Baked-in noise would destroy compression, because noise is exactly what a codec cannot
 * predict; the parameter set is free and leaves a smooth, cheap image underneath. It also hides the
 * banding that heavy compression produces in skies and shadows, which is why an aggressively
 * compressed backdrop can still look filmic rather than blotchy.
 *
 * Encoders, in order of preference:
 *   1. avifenc with AOM  — AVIF + native film grain (the good path; needs the binary)
 *   2. in-process AVIF   — Imagick's or GD's own AVIF encoder, no grain
 *   3. cwebp             — a better WebP than GD writes, and shared hosts tend to have the binary
 *   4. in-process WebP   — last resort, roughly double the bytes for the same look
 *
 * WHICH RUNG A HOST REACHES IS NOT GUESSABLE, and assuming otherwise is what hid a bug for months.
 * The pixel work used to be written straight against the Imagick class, on the theory that shared
 * hosting lands on Imagick AVIF. Production has no imagick extension at all: every call threw
 * `Class "Imagick" not found`, nothing was ever optimised, and every background on the live site
 * was served as its full multi-megabyte original. Production has PHP-GD with AVIF, cwebp, and no
 * avifenc; this Mac has Imagick and avifenc and a GD built without AVIF. So the pixels live behind
 * ImageCanvas and each rung is asked, not assumed.
 */
class BackgroundImageOptimizer
{
    /** Fewest quality steps worth trying; each probe is a full encode, so this is a real cost. */
    private const SEARCH_ITERATIONS = 5;

    private const QUALITY_FLOOR = 18;

    /** Below this the picture is mush; better to exceed the cap and log it than ship a smear. */
    private const QUALITY_ABSOLUTE_FLOOR = 15;

    public function __construct(
        private readonly int $maxBytes,
        private readonly int $maxWidth,
        private readonly int $maxHeight,
        private readonly int $grainPreset,
        private readonly string $avifencPath,
        private readonly int $startQuality,
        private readonly int $speed,
        private readonly string $cwebpPath = 'cwebp',
        private readonly CanvasFactory $canvases = new CanvasFactory,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxBytes: (int) config('services.imagery.max_bytes', 100_000),
            maxWidth: (int) config('services.imagery.max_width', 1920),
            maxHeight: (int) config('services.imagery.max_height', 1080),
            grainPreset: (int) config('services.imagery.grain_preset', 1),
            avifencPath: (string) config('services.imagery.avifenc_path', 'avifenc'),
            startQuality: (int) config('services.imagery.start_quality', 60),
            speed: (int) config('services.imagery.encoder_speed', 6),
            cwebpPath: (string) config('services.imagery.cwebp_path', 'cwebp'),
        );
    }

    /**
     * Optimise raw image bytes.
     *
     * @param  string  $bytes  the downloaded original
     * @return array{bytes:string,extension:string,width:int,height:int,quality:int,grain:bool}
     *
     * @throws RuntimeException when the bytes are not a readable image
     */
    public function optimise(string $bytes): array
    {
        $canvas = $this->canvases->make($bytes);

        try {
            $canvas->resizeToFit($this->maxWidth, $this->maxHeight);

            // No transparency to preserve? Drop the channel. It is a whole extra encoded plane —
            // an opaque painting carrying an alpha mask pays for a mask that says "all opaque".
            if (! $canvas->hasRealTransparency()) {
                $canvas->flatten();
            }

            $width = $canvas->width();
            $height = $canvas->height();

            $encoded = $this->encodeUnderCap($canvas);

            return [
                ...$encoded,
                'width' => $width,
                'height' => $height,
            ];
        } finally {
            $canvas->close();
        }
    }

    /**
     * One-pass encode at a fixed quality, for bulk archiving.
     *
     * optimise() spends five encodes hunting the highest quality that fits the byte cap, which is
     * right for a background a class will actually watch and hopeless for fourteen hundred archive
     * files — that search is the difference between forty minutes and six hours. The result lands
     * at the same stage size and format, so an archived image can be reused as a background by
     * copying it, with no second generation of loss.
     *
     * @return array{bytes:string,extension:string,width:int,height:int,quality:int,grain:bool}
     */
    public function archive(string $bytes, int $quality = 45): array
    {
        $canvas = $this->canvases->make($bytes);

        try {
            $canvas->resizeToFit($this->maxWidth, $this->maxHeight);

            if (! $canvas->hasRealTransparency()) {
                $canvas->flatten();
            }

            $width = $canvas->width();
            $height = $canvas->height();

            $source = $this->writeTemporarySource($canvas);
            try {
                $encoded = $this->encodeOnce($source, $canvas, $quality);
            } finally {
                @unlink($source);
            }

            return [...$encoded, 'width' => $width, 'height' => $height];
        } finally {
            $canvas->close();
        }
    }

    /**
     * Find the highest quality that still fits the byte cap.
     *
     * Binary search rather than a descending ladder: each probe is a full encode of a Full HD
     * image, so the difference between five probes and a dozen is real wall-clock time on a queue
     * worker. Every fitting result is kept, so the search returns the best one it saw.
     *
     * @return array{bytes:string,extension:string,quality:int,grain:bool}
     */
    private function encodeUnderCap(ImageCanvas $canvas): array
    {
        $source = $this->writeTemporarySource($canvas);

        try {
            $low = self::QUALITY_ABSOLUTE_FLOOR;
            $high = $this->startQuality;
            $best = null;

            for ($i = 0; $i < self::SEARCH_ITERATIONS && $low <= $high; $i++) {
                $quality = intdiv($low + $high, 2);
                $candidate = $this->encodeOnce($source, $canvas, $quality);

                if (strlen($candidate['bytes']) <= $this->maxBytes) {
                    $best = $candidate;         // fits — try to spend the remaining budget on quality
                    $low = $quality + 1;
                } else {
                    $high = $quality - 1;
                }
            }

            if ($best !== null) {
                return $best;
            }

            // Nothing fit. Encode once at the floor and ship it over budget rather than blank: a
            // heavy background still teaches the lesson, a missing one does not.
            $fallback = $this->encodeOnce($source, $canvas, self::QUALITY_FLOOR);
            Log::info('BackgroundImageOptimizer: could not reach the byte cap', [
                'cap_bytes' => $this->maxBytes,
                'actual_bytes' => strlen($fallback['bytes']),
                'quality' => self::QUALITY_FLOOR,
            ]);

            return $fallback;
        } finally {
            @unlink($source);
        }
    }

    /** The CLI encoders read a file, not a stream, so the resized image lands on disk once. */
    private function writeTemporarySource(ImageCanvas $canvas): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bg-src-').'.png';
        $canvas->writePng($path);

        return $path;
    }

    /**
     * One trip down the encoder ladder.
     *
     * @return array{bytes:string,extension:string,quality:int,grain:bool}
     *
     * @throws RuntimeException when the host can write neither AVIF nor WebP by any route
     */
    private function encodeOnce(string $sourcePath, ImageCanvas $canvas, int $quality): array
    {
        $avifWithGrain = $this->encodeAvifWithGrain($sourcePath, $quality);
        if ($avifWithGrain !== null) {
            return ['bytes' => $avifWithGrain, 'extension' => 'avif', 'quality' => $quality, 'grain' => true];
        }

        $avif = $canvas->encode('avif', $quality);
        if ($avif !== null) {
            return ['bytes' => $avif, 'extension' => 'avif', 'quality' => $quality, 'grain' => false];
        }

        // Above the in-process WebP rung on purpose: libwebp's own encoder at method 6 beats what
        // GD's imagewebp() produces for the same quality number, and production has the binary.
        $cwebp = $this->encodeWebpWithCwebp($sourcePath, $quality);
        if ($cwebp !== null) {
            return ['bytes' => $cwebp, 'extension' => 'webp', 'quality' => $quality, 'grain' => false];
        }

        $webp = $canvas->encode('webp', $quality);
        if ($webp !== null) {
            return ['bytes' => $webp, 'extension' => 'webp', 'quality' => $quality, 'grain' => false];
        }

        throw new RuntimeException(
            'no usable image encoder: this host can write neither AVIF nor WebP '
            .'(tried avifenc, the '.$this->canvases->backendName().' extension, and cwebp)'
        );
    }

    /**
     * The good path: AVIF whose grain the decoder draws.
     *
     * Returns null — rather than throwing — when avifenc is absent or not an AOM build, because
     * that is the normal state of shared hosting and the caller has a working fallback.
     */
    private function encodeAvifWithGrain(string $sourcePath, int $quality): ?string
    {
        if (! $this->avifencSupportsGrain()) {
            return null;
        }

        $destination = tempnam(sys_get_temp_dir(), 'bg-out-').'.avif';

        try {
            $process = new Process([
                $this->avifencPath,
                '-c', 'aom',
                '-q', (string) $quality,
                '-s', (string) $this->speed,
                // "color:" scope on purpose. Unscoped, this also reaches the ALPHA encoder, and
                // grain on a transparency mask makes the edges of a cut-out crawl.
                '-a', "color:film-grain-test={$this->grainPreset}",
                '--', $sourcePath, $destination,
            ]);
            $process->setTimeout(120);
            $process->mustRun();

            $bytes = @file_get_contents($destination);

            return $bytes === false || $bytes === '' ? null : $bytes;
        } catch (ProcessFailedException $e) {
            Log::warning('BackgroundImageOptimizer: avifenc failed, falling back', [
                'quality' => $quality,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        } finally {
            @unlink($destination);
        }
    }

    /** libwebp's own encoder, where the binary exists. Null when it does not — same contract. */
    private function encodeWebpWithCwebp(string $sourcePath, int $quality): ?string
    {
        if (! $this->cwebpAvailable()) {
            return null;
        }

        $destination = tempnam(sys_get_temp_dir(), 'bg-out-').'.webp';

        try {
            $process = new Process([
                $this->cwebpPath,
                '-quiet',
                '-q', (string) $quality,
                '-m', '6',      // slowest search, smallest file; we encode once and serve forever
                $sourcePath,
                '-o', $destination,
            ]);
            $process->setTimeout(120);
            $process->mustRun();

            $bytes = @file_get_contents($destination);

            return $bytes === false || $bytes === '' ? null : $bytes;
        } catch (ProcessFailedException $e) {
            Log::warning('BackgroundImageOptimizer: cwebp failed, falling back', [
                'quality' => $quality,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        } finally {
            @unlink($destination);
        }
    }

    /** Probed once per instance — spawning a binary per quality probe would be silly. */
    private ?bool $grainSupported = null;

    private ?bool $cwebpFound = null;

    private function avifencSupportsGrain(): bool
    {
        if ($this->grainSupported !== null) {
            return $this->grainSupported;
        }

        try {
            $process = new Process([$this->avifencPath, '--version']);
            $process->setTimeout(10);
            $process->run();
            // AOM specifically: film-grain-test is an AOM option, and a rav1e or svt build would
            // encode a perfectly valid file with no grain in it and say nothing.
            $this->grainSupported = $process->isSuccessful()
                && str_contains(strtolower($process->getOutput().$process->getErrorOutput()), 'aom');
        } catch (\Throwable) {
            $this->grainSupported = false;
        }

        return $this->grainSupported;
    }

    private function cwebpAvailable(): bool
    {
        if ($this->cwebpFound !== null) {
            return $this->cwebpFound;
        }

        try {
            $process = new Process([$this->cwebpPath, '-version']);
            $process->setTimeout(10);
            $process->run();
            $this->cwebpFound = $process->isSuccessful();
        } catch (\Throwable) {
            $this->cwebpFound = false;
        }

        return $this->cwebpFound;
    }
}
