<?php

declare(strict_types=1);

namespace App\Services\Imaging;

use Imagick;
use ImagickException;
use RuntimeException;

/**
 * ImageMagick behind the canvas interface.
 *
 * The better backend where it exists: Lanczos resampling, a real alpha-channel range query, and
 * usually both AVIF and WebP delegates. It is what local development runs on. It is NOT what
 * production runs on — SiteGround ships no imagick extension at all.
 */
final class ImagickCanvas implements ImageCanvas
{
    /** Process-wide and constant. queryFormats() builds a several-hundred-entry array each call. */
    private static ?array $formats = null;

    /** Set by flatten(); the encoder then strips the channel from its copy as well, belt and braces. */
    private bool $flattened = false;

    private bool $closed = false;

    private function __construct(private Imagick $image) {}

    /**
     * @throws RuntimeException when the bytes are not a readable image
     */
    public static function fromBytes(string $bytes): self
    {
        try {
            $image = new Imagick;
            $image->readImageBlob($bytes);
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            // Orientation is baked in here; a rotated original would otherwise be cropped sideways.
            $image->autoOrient();

            return new self($image);
        } catch (ImagickException $e) {
            throw new RuntimeException('not a readable image: '.$e->getMessage(), previous: $e);
        }
    }

    public function width(): int
    {
        return $this->image->getImageWidth();
    }

    public function height(): int
    {
        return $this->image->getImageHeight();
    }

    public function resizeToFit(int $maxWidth, int $maxHeight): void
    {
        if ($this->width() <= $maxWidth && $this->height() <= $maxHeight) {
            return;
        }

        $this->image->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1, true);
        $this->image->stripImage();  // EXIF, colour profiles, thumbnails — none of it reaches the screen
    }

    public function hasRealTransparency(): bool
    {
        if (! $this->image->getImageAlphaChannel()) {
            return false;
        }

        try {
            $range = $this->image->getImageChannelRange(Imagick::CHANNEL_ALPHA);
        } catch (ImagickException) {
            return true;    // cannot tell — keep the channel rather than flatten something visible
        }

        $quantum = $this->image->getQuantumRange()['quantumRangeLong'];

        // Fully opaque everywhere means the minimum alpha is still the maximum value.
        return $range['minima'] < $quantum;
    }

    public function flatten(): void
    {
        $this->image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $this->image->setImageBackgroundColor('black');

        $merged = $this->image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $this->image->clear();      // mergeImageLayers returns a NEW image; the old one is ours to free
        $this->image = $merged;
        $this->flattened = true;
    }

    public function writePng(string $path): void
    {
        $this->image->setImageFormat('png');

        if (! $this->image->writeImage($path)) {
            throw new RuntimeException("could not write the intermediate PNG to {$path}");
        }
    }

    public function encode(string $format, int $quality): ?string
    {
        if (! $this->supports($format)) {
            return null;
        }

        $copy = clone $this->image;

        try {
            $copy->setImageFormat($format);
            $copy->setImageCompressionQuality($quality);
            if ($this->flattened) {
                $copy->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }

            return $copy->getImageBlob();
        } finally {
            $copy->clear();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->image->clear();
        $this->closed = true;
    }

    private function supports(string $format): bool
    {
        self::$formats ??= array_map('strtoupper', Imagick::queryFormats());

        return in_array(strtoupper($format), self::$formats, true);
    }
}
