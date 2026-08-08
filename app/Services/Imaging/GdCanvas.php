<?php

declare(strict_types=1);

namespace App\Services\Imaging;

use GdImage;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PHP-GD behind the canvas interface. This is the backend production actually runs on.
 *
 * GD is the plainer library and the differences are worth stating rather than papering over:
 *
 *  - it ignores EXIF orientation, so a phone photograph would come out sideways unless we rotate
 *    it ourselves (see applyExifOrientation);
 *  - it has no alpha-channel range query, so "is this alpha channel actually used" is answered by
 *    sampling (see hasRealTransparency);
 *  - imagecopyresampled is a bilinear-ish downscale, softer than Imagick's Lanczos. At the sizes
 *    we work at — a 3840px original down to 1920px — the difference survives the AVIF encoder
 *    barely at all;
 *  - its AVIF and WebP quality arguments run 0-100 like everyone else's and mean something
 *    slightly different from Imagick's and from avifenc's. Same number, different picture.
 */
final class GdCanvas implements ImageCanvas
{
    /**
     * Roughly how many pixels the transparency check looks at.
     *
     * Every pixel of a Full HD frame is two million imagecolorat() calls, per image, on a shared
     * host. A ~20k grid costs a hundredth of that.
     */
    private const ALPHA_SAMPLES = 20_000;

    /**
     * GD's AVIF effort dial, 0-10, higher is faster and worse — the same direction as avifenc -s,
     * and GD's own default. The quality search runs five encodes per background, so this is the
     * difference between a queue job and a coffee break.
     */
    private const AVIF_SPEED = 6;

    /** Set by flatten(): the encoders must then write no alpha plane at all. */
    private bool $opaque = false;

    private bool $closed = false;

    private function __construct(private GdImage $image) {}

    /**
     * @throws RuntimeException when the bytes are not a readable image
     */
    public static function fromBytes(string $bytes): self
    {
        self::assertFitsInMemory($bytes);

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('not a readable image: GD could not decode the bytes');
        }

        // A palette image would make imagecolorat() return colour INDEXES, and the transparency
        // sampler would then read palette numbers as if they were pixels.
        if (! imageistruecolor($image) && ! imagepalettetotruecolor($image)) {
            imagedestroy($image);
            throw new RuntimeException('GD could not convert a palette image to truecolor');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $canvas = new self($image);
        $canvas->applyExifOrientation($bytes);

        return $canvas;
    }

    public function width(): int
    {
        return imagesx($this->image);
    }

    public function height(): int
    {
        return imagesy($this->image);
    }

    public function resizeToFit(int $maxWidth, int $maxHeight): void
    {
        $width = $this->width();
        $height = $this->height();

        if ($width <= $maxWidth && $height <= $maxHeight) {
            return;
        }

        $scale = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        // Blending OFF on the destination means imagecopyresampled copies the source alpha through
        // instead of compositing it against the black a new truecolor image starts life as.
        imagealphablending($resized, false);
        imagesavealpha($resized, ! $this->opaque);
        imagefilledrectangle($resized, 0, 0, $newWidth - 1, $newHeight - 1,
            (int) imagecolorallocatealpha($resized, 0, 0, 0, 127));

        $copied = imagecopyresampled($resized, $this->image,
            0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        if (! $copied) {
            imagedestroy($resized);
            throw new RuntimeException("GD could not resize {$width}x{$height} to {$newWidth}x{$newHeight}");
        }

        imagedestroy($this->image);
        $this->image = $resized;

        // No stripImage() equivalent is needed: imagewebp/imageavif write no EXIF or colour
        // profile in the first place, and the intermediate PNG carries none either.
    }

    /**
     * Sampled, not exhaustive — and the trade is real.
     *
     * A grid of ~20k points over a 1920x1080 frame steps about 10px at a time, so it finds any
     * translucent region bigger than roughly 10x10 pixels. What it will miss is a hairline: a
     * cut-out whose only transparency is a one-pixel soft edge reads as opaque and gets flattened
     * onto black. That is the right way round — the cost of a false "transparent" is an entire
     * pointless alpha plane on every single background, on every lesson, forever.
     */
    public function hasRealTransparency(): bool
    {
        $width = $this->width();
        $height = $this->height();
        $stride = max(1, (int) floor(sqrt(($width * $height) / self::ALPHA_SAMPLES)));

        for ($y = 0; $y < $height; $y += $stride) {
            for ($x = 0; $x < $width; $x += $stride) {
                // GD packs alpha in bits 24-30: 0 is opaque, 127 is invisible.
                if ((imagecolorat($this->image, $x, $y) >> 24) & 0x7F) {
                    return true;
                }
            }
        }

        return false;
    }

    public function flatten(): void
    {
        $width = $this->width();
        $height = $this->height();

        // A fresh truecolor image is black and fully opaque, which is exactly the backdrop we want.
        $flat = imagecreatetruecolor($width, $height);
        imagealphablending($flat, true);    // ON, so the source composites against that black
        imagecopy($flat, $this->image, 0, 0, 0, 0, $width, $height);
        imagesavealpha($flat, false);       // and the encoders write no alpha plane at all

        imagedestroy($this->image);
        $this->image = $flat;
        $this->opaque = true;
    }

    public function writePng(string $path): void
    {
        if (! @imagepng($this->image, $path)) {
            throw new RuntimeException("could not write the intermediate PNG to {$path}");
        }
    }

    public function encode(string $format, int $quality): ?string
    {
        $format = strtolower($format);

        if (! $this->canWrite($format)) {
            return null;
        }

        // Output buffering, because GD writes to a file or to stdout and never to a string. The
        // encoder call is silenced on purpose: in CLI a PHP warning goes to stdout, which is
        // INSIDE this buffer, and would end up spliced into the image bytes.
        ob_start();
        $written = $format === 'avif'
            ? @imageavif($this->image, null, $quality, self::AVIF_SPEED)
            : @imagewebp($this->image, null, $quality);
        $bytes = (string) ob_get_clean();

        if (! $written || $bytes === '') {
            // Present but unhappy — a build with the function compiled in and the delegate missing
            // behaves exactly like this. Say so, then let the caller drop to the next encoder.
            Log::warning('GdCanvas: the '.$format.' encoder is available but produced nothing', [
                'quality' => $quality,
                'size' => $this->width().'x'.$this->height(),
            ]);

            return null;
        }

        return $bytes;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        imagedestroy($this->image);
        $this->closed = true;
    }

    private function canWrite(string $format): bool
    {
        return match ($format) {
            'avif' => function_exists('imageavif'),
            'webp' => function_exists('imagewebp'),
            default => false,
        };
    }

    /**
     * Rotate the pixels the way the camera meant them to be seen.
     *
     * Imagick does this for us with autoOrient(); GD does nothing at all, and a lesson background
     * that is silently ninety degrees out is worse than one that is merely large. The mapping
     * below was checked against Imagick's autoOrient() for all eight EXIF orientation values, on
     * an image with four distinguishable corners, and matches it exactly.
     *
     * @throws RuntimeException when the rotation itself fails — better than a sideways backdrop
     */
    private function applyExifOrientation(string $bytes): void
    {
        $orientation = $this->readOrientation($bytes);

        // imagerotate() turns COUNTER-clockwise; the flip, where there is one, comes first.
        [$rotate, $flip] = match ($orientation) {
            2 => [0, IMG_FLIP_HORIZONTAL],
            3 => [180, null],
            4 => [0, IMG_FLIP_VERTICAL],
            5 => [90, IMG_FLIP_HORIZONTAL],
            6 => [270, null],
            7 => [270, IMG_FLIP_HORIZONTAL],
            8 => [90, null],
            default => [0, null],
        };

        if ($flip !== null) {
            imageflip($this->image, $flip);
        }

        if ($rotate === 0) {
            return;
        }

        $rotated = imagerotate($this->image, (float) $rotate, 0);

        if ($rotated === false) {
            throw new RuntimeException("GD could not apply EXIF orientation {$orientation}");
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagedestroy($this->image);
        $this->image = $rotated;
    }

    /** @return int a valid EXIF orientation 1-8; 1 (upright) whenever we cannot tell */
    private function readOrientation(string $bytes): int
    {
        // Orientation lives in an EXIF APP1 segment, which only JPEG and TIFF carry. Skipping the
        // rest keeps the whole business off the path of every PNG we handle.
        if (! function_exists('exif_read_data') || ! $this->carriesExif($bytes)) {
            return 1;
        }

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            // A stream rather than a data:// URI on purpose: data:// is gated behind
            // allow_url_fopen, which shared hosts switch off. Verified — with allow_url_fopen=0 a
            // data:// read returns false and this one still reads the tag.
            // The @ is for images that simply have no EXIF block; that is not an error here.
            $exif = @exif_read_data($stream);
        } finally {
            fclose($stream);
        }

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Refuse an original that will not fit in the memory limit, before GD tries.
     *
     * GD holds a decoded image as four bytes per pixel of PHP-allocated memory, so a 5500x4920
     * Commons original wants ~110 MB all at once. Asking for that with too little headroom does
     * not fail politely: measured here it sometimes returns false and sometimes takes the whole
     * process down mid-allocation, which on a queue worker is a job that vanishes without a line
     * in the log. A guess that is refused with a readable message beats either.
     *
     * @throws RuntimeException when the decoded image cannot fit
     */
    private static function assertFitsInMemory(string $bytes): void
    {
        $limit = self::memoryLimitBytes();
        $size = @getimagesizefromstring($bytes);

        if ($limit === null || $size === false) {
            return;     // no limit, or not an image we can measure — let GD give its own verdict
        }

        // Four bytes a pixel plus a quarter for the decoder's own scratch and the row table.
        $needed = (int) ($size[0] * $size[1] * 5);
        $available = $limit - memory_get_usage(true);

        if ($needed > $available) {
            throw new RuntimeException(sprintf(
                'image too large for this host: %dx%d needs about %d MB and only %d MB of the %d MB '
                .'memory_limit is left. Raise memory_limit or install the imagick extension.',
                $size[0], $size[1], $needed >> 20, max(0, $available) >> 20, $limit >> 20,
            ));
        }
    }

    /** @return int|null bytes, or null when the limit is unlimited or unreadable */
    private static function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function carriesExif(string $bytes): bool
    {
        return str_starts_with($bytes, "\xFF\xD8")        // JPEG
            || str_starts_with($bytes, "II\x2A\x00")      // TIFF, little endian
            || str_starts_with($bytes, "MM\x00\x2A");     // TIFF, big endian
    }
}
