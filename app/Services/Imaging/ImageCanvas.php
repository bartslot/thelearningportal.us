<?php

declare(strict_types=1);

namespace App\Services\Imaging;

use RuntimeException;

/**
 * One loaded image, and only the operations a scene backdrop actually needs.
 *
 * This exists because the image libraries a host has are not guessable. Production (SiteGround)
 * has PHP-GD compiled with AVIF and no Imagick extension at all; this Mac has Imagick and avifenc
 * and a GD built without AVIF. BackgroundImageOptimizer was written straight against the Imagick
 * class, so on production every call died on `Class "Imagick" not found` and every lesson
 * background was served at its full multi-megabyte size for months.
 *
 * So: the optimiser owns the policy (stage size, byte cap, quality search) and a canvas owns the
 * pixels. A caller never sees an Imagick object or a GdImage — if it did, the next backend would
 * leak back out through the seams.
 *
 * Implementations are MUTABLE and single-use: resizeToFit() and flatten() change the image in
 * place, and close() ends its life. One canvas per source image, always released in a finally.
 */
interface ImageCanvas
{
    /** Current width in pixels, after any resize. */
    public function width(): int;

    /** Current height in pixels, after any resize. */
    public function height(): int;

    /**
     * Scale down to fit inside the box, preserving aspect ratio. NEVER enlarges.
     *
     * Upscaling spends bytes inventing detail the original never had — a 900px painting stays
     * 900px and the stage scales it, which looks the same and costs a third as much.
     *
     * @throws RuntimeException when the backend cannot produce the resized image
     */
    public function resizeToFit(int $maxWidth, int $maxHeight): void;

    /**
     * Does the image actually USE its alpha channel, or merely have one?
     *
     * A PNG from Wikimedia Commons almost always carries an alpha channel that is opaque
     * everywhere. Trusting "has a channel" alone would keep a pointless second plane on every
     * background we ever encode.
     */
    public function hasRealTransparency(): bool;

    /**
     * Composite onto black and drop the alpha channel for good.
     *
     * An opaque painting carrying an alpha mask pays for a whole extra encoded plane that says
     * "all opaque".
     */
    public function flatten(): void;

    /**
     * Write the current pixels to a PNG file, losslessly.
     *
     * The CLI encoders read a file rather than a stream, so the resized image lands on disk once
     * and every quality probe reuses it.
     *
     * @throws RuntimeException when the file cannot be written
     */
    public function writePng(string $path): void;

    /**
     * Encode the current pixels in memory.
     *
     * @param  string  $format  'avif' or 'webp'
     * @param  int  $quality  0-100, higher is better. The scale is NOT comparable between
     *                        backends: Imagick q45 and GD q45 are different pictures.
     * @return string|null encoded bytes, or null when this backend cannot write that format —
     *                     which is normal, and means the caller should try the next encoder
     */
    public function encode(string $format, int $quality): ?string;

    /** Release the underlying image. Safe to call twice. */
    public function close(): void;
}
