<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Re-encode arbitrary image bytes to WebP at a given quality. Used to keep stored
 * scene assets small — a lossless PNG storyboard shot is ~1 MB, the same cell as
 * WebP at quality 60 is ~110 KB with no visible loss at player resolution.
 *
 * Quality is WebP-style: 100 ≈ lossless/largest, 60 ≈ high-res-but-small, lower ≈ smaller.
 * Falls back to the original bytes when GD or WebP support is unavailable, or when the
 * bytes can't be decoded — callers never have to guard for it.
 */
final class WebpEncoder
{
    public static function encode(string $bytes, int $quality): string
    {
        if ($bytes === '' || ! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return $bytes;
        }

        // Preserve transparency (hero poses / cut-outs) — WebP supports alpha, but GD only
        // writes it when save-alpha is on and blending is off.
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $quality = max(0, min(100, $quality));

        ob_start();
        imagewebp($img, null, $quality);
        $out = (string) ob_get_clean();

        // Free the GD handle — encode() runs on every generated/upscaled image inside a
        // long-lived queue worker, so a leaked handle here accumulates until OOM.
        imagedestroy($img);

        return $out !== '' ? $out : $bytes;
    }
}
