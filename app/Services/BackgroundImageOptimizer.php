<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickException;
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
 *   2. Imagick AVIF      — AVIF, no grain (shared hosting usually lands here)
 *   3. Imagick WebP      — last resort, roughly double the bytes for the same look
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
        $image = $this->readImage($bytes);

        try {
            $this->fitToStage($image);
            $hasAlpha = $this->hasRealTransparency($image);

            // No transparency to preserve? Drop the channel. It is a whole extra encoded plane —
            // an opaque painting carrying an alpha mask pays for a mask that says "all opaque".
            if (! $hasAlpha) {
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $image->setImageBackgroundColor('black');
                $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            $encoded = $this->encodeUnderCap($image, $hasAlpha);

            return [
                ...$encoded,
                'width' => $width,
                'height' => $height,
            ];
        } finally {
            $image->clear();
        }
    }

    private function readImage(string $bytes): Imagick
    {
        try {
            $image = new Imagick;
            $image->readImageBlob($bytes);
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            // Orientation is baked in here; a rotated original would otherwise be cropped sideways.
            $image->autoOrient();

            return $image;
        } catch (ImagickException $e) {
            throw new RuntimeException('not a readable image: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Scale down to fit the stage, never up.
     *
     * Enlarging a small original wastes bytes inventing detail that is not there — a 900px painting
     * stays 900px and the stage scales it, which looks the same and costs a third as much.
     */
    private function fitToStage(Imagick $image): void
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        if ($width <= $this->maxWidth && $height <= $this->maxHeight) {
            return;
        }

        $image->resizeImage($this->maxWidth, $this->maxHeight, Imagick::FILTER_LANCZOS, 1, true);
        $image->stripImage();   // EXIF, colour profiles, thumbnails — none of it reaches the screen
    }

    /**
     * Does the image actually use its alpha channel, or merely have one?
     *
     * A PNG from Commons almost always carries an alpha channel that is opaque everywhere. Trusting
     * getImageAlphaChannel() alone would keep a pointless second plane on every single background.
     */
    private function hasRealTransparency(Imagick $image): bool
    {
        if (! $image->getImageAlphaChannel()) {
            return false;
        }

        try {
            $range = $image->getImageChannelRange(Imagick::CHANNEL_ALPHA);
        } catch (ImagickException) {
            return true;    // cannot tell — keep the channel rather than flatten something visible
        }

        $quantum = $image->getQuantumRange()['quantumRangeLong'];

        // Fully opaque everywhere means the minimum alpha is still the maximum value.
        return $range['minima'] < $quantum;
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
    private function encodeUnderCap(Imagick $image, bool $hasAlpha): array
    {
        $source = $this->writeTemporarySource($image, $hasAlpha);

        try {
            $low = self::QUALITY_ABSOLUTE_FLOOR;
            $high = $this->startQuality;
            $best = null;

            for ($i = 0; $i < self::SEARCH_ITERATIONS && $low <= $high; $i++) {
                $quality = intdiv($low + $high, 2);
                $candidate = $this->encodeOnce($source, $image, $quality, $hasAlpha);

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
            $fallback = $this->encodeOnce($source, $image, self::QUALITY_FLOOR, $hasAlpha);
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

    /** avifenc reads a file, not a stream, so the resized image lands on disk once and is reused. */
    private function writeTemporarySource(Imagick $image, bool $hasAlpha): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bg-src-').'.png';
        $image->setImageFormat('png');
        $image->writeImage($path);
        unset($hasAlpha);

        return $path;
    }

    /**
     * @return array{bytes:string,extension:string,quality:int,grain:bool}
     */
    private function encodeOnce(string $sourcePath, Imagick $image, int $quality, bool $hasAlpha): array
    {
        $avif = $this->encodeAvifWithGrain($sourcePath, $quality);
        if ($avif !== null) {
            return ['bytes' => $avif, 'extension' => 'avif', 'quality' => $quality, 'grain' => true];
        }

        return $this->encodeWithImagick($image, $quality, $hasAlpha);
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

    /**
     * Imagick's own encoders. AVIF if this build has it (still a big win over WebP, just without
     * the grain), WebP otherwise.
     *
     * @return array{bytes:string,extension:string,quality:int,grain:bool}
     */
    private function encodeWithImagick(Imagick $image, int $quality, bool $hasAlpha): array
    {
        $formats = array_map('strtoupper', Imagick::queryFormats());
        $format = in_array('AVIF', $formats, true) ? 'avif' : 'webp';

        $copy = clone $image;
        try {
            $copy->setImageFormat($format);
            $copy->setImageCompressionQuality($quality);
            if (! $hasAlpha) {
                $copy->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }

            return [
                'bytes' => $copy->getImageBlob(),
                'extension' => $format,
                'quality' => $quality,
                'grain' => false,
            ];
        } finally {
            $copy->clear();
        }
    }

    /** Probed once per request — spawning avifenc --version per quality probe would be silly. */
    private ?bool $grainSupported = null;

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
}
