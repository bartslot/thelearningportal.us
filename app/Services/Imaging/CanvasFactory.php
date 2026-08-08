<?php

declare(strict_types=1);

namespace App\Services\Imaging;

use RuntimeException;

/**
 * Picks the image library this host actually has.
 *
 * An object rather than a static make(), for one reason that matters: with a static call the GD
 * path is unreachable from any machine that has Imagick, which is every development machine and
 * CI — so the backend production runs on would never be executed by a test. As an injected
 * collaborator it can be pinned, and CanvasFactory::gd() in a test exercises the real production
 * path on a laptop that would otherwise always choose Imagick.
 *
 * Imagick first where both exist: Lanczos resampling and a real alpha-range query beat sampling
 * and a bilinear downscale.
 *
 * Deliberately not final: it is the seam the encoder-ladder tests replace with a canvas that
 * refuses to encode, which is the only way to reach the CLI rungs from a machine that has both.
 */
class CanvasFactory
{
    public const AUTO = 'auto';

    public const IMAGICK = 'imagick';

    public const GD = 'gd';

    public function __construct(private readonly string $backend = self::AUTO) {}

    /** Pin to GD — the production backend, and the one a machine with Imagick would never pick. */
    public static function gd(): self
    {
        return new self(self::GD);
    }

    public static function imagick(): self
    {
        return new self(self::IMAGICK);
    }

    /**
     * @throws RuntimeException when no usable backend is installed, or the bytes are not an image
     */
    public function make(string $bytes): ImageCanvas
    {
        return $this->resolve() === self::IMAGICK
            ? ImagickCanvas::fromBytes($bytes)
            : GdCanvas::fromBytes($bytes);
    }

    /** Which library will be used, for logs and for the "why is this so slow" question. */
    public function backendName(): string
    {
        return $this->resolve();
    }

    /**
     * @return self::IMAGICK|self::GD
     *
     * @throws RuntimeException
     */
    private function resolve(): string
    {
        return match ($this->backend) {
            self::IMAGICK => extension_loaded('imagick')
                ? self::IMAGICK
                : throw new RuntimeException('the imagick extension was requested but is not loaded'),
            self::GD => extension_loaded('gd')
                ? self::GD
                : throw new RuntimeException('the gd extension was requested but is not loaded'),
            self::AUTO => $this->bestAvailable(),
            // A misspelt backend must not quietly mean "auto". Choosing the wrong library in
            // silence is the whole reason this class exists.
            default => throw new RuntimeException(
                "unknown image backend '{$this->backend}': expected auto, imagick or gd"
            ),
        };
    }

    /**
     * @return self::IMAGICK|self::GD
     *
     * @throws RuntimeException
     */
    private function bestAvailable(): string
    {
        if (extension_loaded('imagick')) {
            return self::IMAGICK;
        }

        if (extension_loaded('gd')) {
            return self::GD;
        }

        throw new RuntimeException(
            'no image backend available: install the imagick or gd PHP extension to optimise images'
        );
    }
}
