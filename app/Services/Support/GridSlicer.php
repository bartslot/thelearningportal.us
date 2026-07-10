<?php

declare(strict_types=1);

namespace App\Services\Support;

use RuntimeException;

/**
 * Slices a storyboard-grid image into equal cells (GD). One image-gen call produces
 * a 3×3 (or 2×2) grid; each cell becomes a scene shot that is upscaled separately.
 */
final class GridSlicer
{
    /**
     * @return list<string> PNG bytes per cell, left-to-right, top-to-bottom
     */
    public static function slice(string $imageBytes, int $rows, int $cols): array
    {
        if ($rows < 1 || $cols < 1) {
            throw new RuntimeException("Invalid grid {$rows}x{$cols}.");
        }

        $source = @imagecreatefromstring($imageBytes);
        if ($source === false) {
            throw new RuntimeException('GridSlicer: could not decode image bytes.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $cellWidth = intdiv($width, $cols);
        $cellHeight = intdiv($height, $rows);

        $cells = [];
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $cell = imagecreatetruecolor($cellWidth, $cellHeight);
                imagecopy($cell, $source, 0, 0, $col * $cellWidth, $row * $cellHeight, $cellWidth, $cellHeight);

                ob_start();
                imagepng($cell);
                $cells[] = (string) ob_get_clean();
                // No imagedestroy(): it is a deprecated no-op since PHP 8.0 (GdImage is GC'd).
            }
        }

        return $cells;
    }

    /** Parse a "3x3" / "2x2" config string. Returns [rows, cols] or null when disabled. */
    public static function parseGrid(?string $grid): ?array
    {
        if (! is_string($grid) || ! preg_match('/^([1-9])x([1-9])$/', trim($grid), $m)) {
            return null;
        }

        $rows = (int) $m[1];
        $cols = (int) $m[2];

        return $rows * $cols > 1 ? [$rows, $cols] : null;
    }
}
