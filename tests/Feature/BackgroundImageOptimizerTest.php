<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BackgroundImageOptimizer;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use RuntimeException;
use Tests\TestCase;

/**
 * Backgrounds are resized and re-encoded on the way in, because originals arrive at 3840px and
 * 1.7-3 MB and a sixteen-scene lesson made of those is tens of megabytes over a school's wifi.
 */
class BackgroundImageOptimizerTest extends TestCase
{
    private function optimiser(int $maxBytes = 100_000, int $grain = 1): BackgroundImageOptimizer
    {
        return new BackgroundImageOptimizer(
            maxBytes: $maxBytes,
            maxWidth: 1920,
            maxHeight: 1080,
            grainPreset: $grain,
            avifencPath: (string) config('services.imagery.avifenc_path', 'avifenc'),
            startQuality: 60,
            speed: 8,      // faster than the production default; these are tests, not deliverables
        );
    }

    /** A busy photographic image — the case that actually stresses the byte cap. */
    private function noisyPhoto(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newPseudoImage($width, $height, 'plasma:fractal');
        $image->setImageFormat('png');
        $blob = $image->getImageBlob();
        $image->clear();

        return $blob;
    }

    private function transparentCutout(): string
    {
        $image = new Imagick;
        $image->newImage(600, 400, new ImagickPixel('transparent'));
        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('tomato'));
        $draw->circle(300, 200, 500, 200);
        $image->drawImage($draw);
        $image->setImageFormat('png');
        $blob = $image->getImageBlob();
        $image->clear();

        return $blob;
    }

    public function test_a_huge_background_is_brought_under_the_byte_cap(): void
    {
        $original = $this->noisyPhoto(3840, 2160);
        $this->assertGreaterThan(1_000_000, strlen($original), 'fixture should be genuinely large');

        $result = $this->optimiser()->optimise($original);

        $this->assertLessThanOrEqual(100_000, strlen($result['bytes']),
            'a sourced background must fit the byte cap; got '.strlen($result['bytes']).' bytes');
        $this->assertContains($result['extension'], ['avif', 'webp']);
    }

    public function test_an_oversized_image_is_scaled_down_to_the_stage(): void
    {
        $result = $this->optimiser()->optimise($this->noisyPhoto(3840, 2160));

        $this->assertSame(1920, $result['width']);
        $this->assertSame(1080, $result['height']);
    }

    public function test_a_small_image_is_never_enlarged(): void
    {
        // Upscaling spends bytes inventing detail the original never had.
        $result = $this->optimiser()->optimise($this->noisyPhoto(800, 600));

        $this->assertSame(800, $result['width']);
        $this->assertSame(600, $result['height']);
    }

    public function test_an_opaque_image_loses_its_pointless_alpha_channel(): void
    {
        // Commons PNGs routinely carry an alpha channel that is opaque everywhere. Keeping it pays
        // for a whole extra encoded plane that says "all opaque".
        $opaque = new Imagick;
        $opaque->newPseudoImage(1200, 800, 'plasma:fractal');
        $opaque->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
        $opaque->setImageFormat('png');
        $blob = $opaque->getImageBlob();
        $opaque->clear();

        $result = $this->optimiser()->optimise($blob);

        $decoded = new Imagick;
        $decoded->readImageBlob($result['bytes']);
        $hasAlphaChannel = $decoded->getImageAlphaChannel();
        // With no alpha channel present at all, Imagick reports the range as PHP_FLOAT_MAX rather
        // than a real number — "there is no such channel", which is the outcome being asserted.
        $minimumAlpha = $decoded->getImageChannelRange(Imagick::CHANNEL_ALPHA)['minima'];
        $quantum = $decoded->getQuantumRange()['quantumRangeLong'];
        $decoded->clear();

        $this->assertTrue(
            ! $hasAlphaChannel || $minimumAlpha >= $quantum,
            'an opaque background should carry no real alpha',
        );
    }

    public function test_real_transparency_survives(): void
    {
        $result = $this->optimiser()->optimise($this->transparentCutout());

        $decoded = new Imagick;
        $decoded->readImageBlob($result['bytes']);
        $cornerAlpha = $decoded->getImagePixelColor(2, 2)->getColorValue(Imagick::COLOR_ALPHA);
        $decoded->clear();

        $this->assertEqualsWithDelta(0.0, $cornerAlpha, 0.05,
            'a cut-out must not be flattened onto a background');
    }

    public function test_unreadable_bytes_are_rejected_rather_than_stored(): void
    {
        $this->expectException(RuntimeException::class);

        $this->optimiser()->optimise('this is not an image');
    }

    public function test_film_grain_is_carried_by_parameters_not_pixels(): void
    {
        if (! $this->avifencWithAomAvailable()) {
            $this->markTestSkipped('avifenc with AOM is not installed on this machine.');
        }

        // The whole point of native AVIF film grain: the grain costs a parameter set, not bytes of
        // encoded noise. If this ever inverts, someone has started baking noise into the pixels.
        $source = $this->noisyPhoto(1280, 720);

        $withGrain = $this->optimiser(grain: 1)->optimise($source);
        $withoutGrain = $this->optimiser(grain: 0)->optimise($source);

        $overhead = strlen($withGrain['bytes']) - strlen($withoutGrain['bytes']);

        $this->assertTrue($withGrain['grain'], 'grain path should have been taken');
        $this->assertLessThan(2_000, abs($overhead),
            "film grain should cost a few hundred bytes of parameters, not {$overhead}");
    }

    private function avifencWithAomAvailable(): bool
    {
        $binary = (string) config('services.imagery.avifenc_path', 'avifenc');
        $output = @shell_exec(escapeshellcmd($binary).' --version 2>&1') ?? '';

        return str_contains(strtolower($output), 'aom');
    }
}
