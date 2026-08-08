<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\BackgroundImageOptimizer;
use App\Services\Imaging\CanvasFactory;
use App\Services\Imaging\ImageCanvas;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use RuntimeException;
use Tests\TestCase;

/**
 * Backgrounds are resized and re-encoded on the way in, because originals arrive at 3840px and
 * 1.7-3 MB and a sixteen-scene lesson made of those is tens of megabytes over a school's wifi.
 *
 * Half of these tests pin the GD backend on purpose. Production has no imagick extension at all,
 * so on a development machine the automatic choice is never the one production makes — and for
 * months that meant the code path the live site actually runs was executed by nobody, ever.
 */
class BackgroundImageOptimizerTest extends TestCase
{
    /** A path no binary lives at, for simulating a host without avifenc or cwebp. */
    private const NO_BINARY = '/nonexistent/definitely-not-an-encoder';

    private function optimiser(
        int $maxBytes = 100_000,
        int $grain = 1,
        ?CanvasFactory $canvases = null,
        ?string $avifencPath = null,
        ?string $cwebpPath = null,
    ): BackgroundImageOptimizer {
        return new BackgroundImageOptimizer(
            maxBytes: $maxBytes,
            maxWidth: 1920,
            maxHeight: 1080,
            grainPreset: $grain,
            avifencPath: $avifencPath ?? (string) config('services.imagery.avifenc_path', 'avifenc'),
            startQuality: 60,
            speed: 8,      // faster than the production default; these are tests, not deliverables
            cwebpPath: $cwebpPath ?? (string) config('services.imagery.cwebp_path', 'cwebp'),
            canvases: $canvases ?? new CanvasFactory,
        );
    }

    /**
     * The production shape: GD does the pixels and neither CLI encoder exists, so GD's own AVIF
     * or WebP encoder has to deliver the whole result on its own.
     */
    private function gdOnlyOptimiser(int $maxBytes = 100_000): BackgroundImageOptimizer
    {
        return $this->optimiser(
            maxBytes: $maxBytes,
            canvases: CanvasFactory::gd(),
            avifencPath: self::NO_BINARY,
            cwebpPath: self::NO_BINARY,
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

    /**
     * Decode the result with the OTHER library, so "this is a valid image" is not the opinion of
     * the encoder that just wrote it.
     */
    private function assertDecodesTo(string $bytes, int $width, int $height): void
    {
        $decoded = new Imagick;
        $decoded->readImageBlob($bytes);        // throws unless these really are image bytes
        $actual = [$decoded->getImageWidth(), $decoded->getImageHeight()];
        $decoded->clear();

        $this->assertSame([$width, $height], $actual, 'the encoded image should be stage-sized');
    }

    private function assertCarriesNoRealAlpha(string $bytes): void
    {
        $decoded = new Imagick;
        $decoded->readImageBlob($bytes);
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
        $result = $this->optimiser()->optimise($this->opaqueButAlphaCarrying());

        $this->assertCarriesNoRealAlpha($result['bytes']);
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

    // ── The GD backend: what production actually runs ────────────────────────────────────────

    public function test_the_gd_backend_alone_brings_a_huge_background_under_the_cap(): void
    {
        $original = $this->noisyPhoto(3840, 2160);

        $result = $this->gdOnlyOptimiser()->optimise($original);

        $this->assertLessThanOrEqual(100_000, strlen($result['bytes']),
            'GD must reach the byte cap unaided; got '.strlen($result['bytes']).' bytes');
        $this->assertLessThan(strlen($original), strlen($result['bytes']));
        $this->assertContains($result['extension'], ['avif', 'webp']);
        $this->assertSame(1920, $result['width']);
        $this->assertSame(1080, $result['height']);
        $this->assertDecodesTo($result['bytes'], 1920, 1080);
    }

    public function test_the_gd_backend_never_enlarges_a_small_original(): void
    {
        $result = $this->gdOnlyOptimiser()->optimise($this->noisyPhoto(800, 600));

        $this->assertSame(800, $result['width']);
        $this->assertSame(600, $result['height']);
        $this->assertDecodesTo($result['bytes'], 800, 600);
    }

    public function test_the_gd_backend_keeps_a_cut_out_transparent(): void
    {
        // GD has no alpha-range query, so this exercises the sampled transparency check.
        $result = $this->gdOnlyOptimiser()->optimise($this->transparentCutout());

        $decoded = new Imagick;
        $decoded->readImageBlob($result['bytes']);
        $cornerAlpha = $decoded->getImagePixelColor(2, 2)->getColorValue(Imagick::COLOR_ALPHA);
        $decoded->clear();

        $this->assertEqualsWithDelta(0.0, $cornerAlpha, 0.05,
            'a cut-out must not be flattened onto a background');
    }

    public function test_the_gd_backend_drops_a_pointless_alpha_channel(): void
    {
        $result = $this->gdOnlyOptimiser()->optimise($this->opaqueButAlphaCarrying());

        $this->assertCarriesNoRealAlpha($result['bytes']);
    }

    public function test_the_gd_backend_rejects_unreadable_bytes(): void
    {
        $this->expectException(RuntimeException::class);

        $this->gdOnlyOptimiser()->optimise('this is not an image');
    }

    public function test_the_gd_backend_honours_exif_orientation(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('the exif extension is not loaded on this machine.');
        }

        // GD ignores EXIF entirely; Imagick autoOrients for free. A phone photograph tagged
        // "rotate 90" must come out portrait on both, or the backdrop is sideways on production
        // and upright everywhere it was tested.
        $sideways = $this->jpegWithOrientation($this->photoJpeg(800, 600), 6);

        $result = $this->gdOnlyOptimiser()->optimise($sideways);

        $this->assertSame(600, $result['width'], 'EXIF orientation 6 should have been applied');
        $this->assertSame(800, $result['height']);
    }

    // ── The encoder ladder ───────────────────────────────────────────────────────────────────

    public function test_the_cwebp_rung_catches_a_host_with_no_avif_encoder(): void
    {
        if (! $this->cwebpAvailable()) {
            $this->markTestSkipped('cwebp is not installed on this machine.');
        }

        // No avifenc, and a canvas whose own encoders refuse everything: exactly the host that
        // has nothing but the libwebp binary. It must still produce a usable background.
        $source = $this->noisyPhoto(1200, 800);
        $optimiser = $this->optimiser(
            canvases: $this->refusingCanvasFactory($source),
            avifencPath: self::NO_BINARY,
        );

        $result = $optimiser->optimise($source);

        $this->assertSame('webp', $result['extension']);
        $this->assertFalse($result['grain']);
        $this->assertLessThanOrEqual(100_000, strlen($result['bytes']));
        $this->assertDecodesTo($result['bytes'], 1200, 800);
    }

    public function test_a_host_that_can_encode_nothing_says_so_instead_of_storing_junk(): void
    {
        $source = $this->noisyPhoto(400, 300);
        $optimiser = $this->optimiser(
            canvases: $this->refusingCanvasFactory($source),
            avifencPath: self::NO_BINARY,
            cwebpPath: self::NO_BINARY,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no usable image encoder');

        $optimiser->optimise($source);
    }

    public function test_the_canvas_factory_can_be_pinned_to_a_backend(): void
    {
        $this->assertSame('gd', CanvasFactory::gd()->backendName());
        $this->assertSame(
            extension_loaded('imagick') ? 'imagick' : 'gd',
            (new CanvasFactory)->backendName(),
            'left to itself the factory should prefer Imagick where it exists',
        );
    }

    public function test_the_canvas_factory_refuses_a_backend_that_is_not_installed(): void
    {
        $missing = extension_loaded('imagick') ? 'not-an-extension' : CanvasFactory::IMAGICK;

        $this->expectException(RuntimeException::class);

        (new CanvasFactory($missing))->make($this->noisyPhoto(10, 10));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────────────

    /** An image that is opaque everywhere but still carries an alpha channel, as Commons PNGs do. */
    private function opaqueButAlphaCarrying(): string
    {
        $opaque = new Imagick;
        $opaque->newPseudoImage(1200, 800, 'plasma:fractal');
        $opaque->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
        $opaque->setImageFormat('png');
        $blob = $opaque->getImageBlob();
        $opaque->clear();

        return $blob;
    }

    private function photoJpeg(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newPseudoImage($width, $height, 'plasma:fractal');
        $image->setImageFormat('jpeg');
        $blob = $image->getImageBlob();
        $image->clear();

        return $blob;
    }

    /**
     * Splice a minimal EXIF APP1 segment carrying nothing but an Orientation tag into a JPEG.
     *
     * Imagick's setImageOrientation() does not write the tag out, so the fixture is built by hand:
     * a little-endian TIFF header and one IFD entry (0x0112 Orientation, SHORT, count 1).
     */
    private function jpegWithOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'II'.pack('v', 0x2A).pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
            .pack('V', 0);
        $payload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /**
     * A factory whose canvases do the pixels for real but refuse to encode.
     *
     * The only way to reach the CLI rungs from a machine that has Imagick AND GD AVIF: without
     * this, the ladder short-circuits at rung two and the rest is never executed anywhere.
     */
    private function refusingCanvasFactory(string $png): CanvasFactory
    {
        $size = getimagesizefromstring($png);
        $this->assertIsArray($size, 'the stub needs a readable PNG to hand back');

        $canvas = $this->createMock(ImageCanvas::class);
        $canvas->method('width')->willReturn((int) $size[0]);
        $canvas->method('height')->willReturn((int) $size[1]);
        $canvas->method('hasRealTransparency')->willReturn(false);
        $canvas->method('encode')->willReturn(null);
        $canvas->method('writePng')->willReturnCallback(
            fn (string $path) => file_put_contents($path, $png),
        );

        $factory = $this->createMock(CanvasFactory::class);
        $factory->method('make')->willReturn($canvas);
        $factory->method('backendName')->willReturn('stub');

        return $factory;
    }

    private function avifencWithAomAvailable(): bool
    {
        $binary = (string) config('services.imagery.avifenc_path', 'avifenc');
        $output = @shell_exec(escapeshellcmd($binary).' --version 2>&1') ?? '';

        return str_contains(strtolower($output), 'aom');
    }

    private function cwebpAvailable(): bool
    {
        $binary = (string) config('services.imagery.cwebp_path', 'cwebp');
        $output = @shell_exec(escapeshellcmd($binary).' -version 2>&1') ?? '';

        return preg_match('/^\d+\.\d+/', trim($output)) === 1;
    }
}
