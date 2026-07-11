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
     * @param  float  $inset  fraction of each cell trimmed from EVERY side (0.0–0.2). Illustrated
     *                        styles (ink/comic) tend to render paper gutters between panels despite
     *                        the prompt ban — a small inset crops them off so full-screen shots
     *                        never show neighbours' margins.
     * @return list<string> PNG bytes per cell, left-to-right, top-to-bottom
     */
    public static function slice(string $imageBytes, int $rows, int $cols, float $inset = 0.0): array
    {
        if ($rows < 1 || $cols < 1) {
            throw new RuntimeException("Invalid grid {$rows}x{$cols}.");
        }
        $inset = max(0.0, min(0.2, $inset));

        $source = @imagecreatefromstring($imageBytes);
        if ($source === false) {
            throw new RuntimeException('GridSlicer: could not decode image bytes.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if (intdiv($width, $cols) < 4 || intdiv($height, $rows) < 4) {
            throw new RuntimeException("GridSlicer: {$width}x{$height} image is too small for a {$rows}x{$cols} grid.");
        }
        $cellWidth = intdiv($width, $cols);
        $cellHeight = intdiv($height, $rows);
        $marginX = (int) round($cellWidth * $inset);
        $marginY = (int) round($cellHeight * $inset);
        $cropWidth = $cellWidth - 2 * $marginX;
        $cropHeight = $cellHeight - 2 * $marginY;

        $cells = [];
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $cell = imagecreatetruecolor($cropWidth, $cropHeight);
                imagecopy(
                    $cell, $source, 0, 0,
                    $col * $cellWidth + $marginX, $row * $cellHeight + $marginY,
                    $cropWidth, $cropHeight,
                );

                ob_start();
                imagepng($cell);
                $cells[] = (string) ob_get_clean();
                // No imagedestroy(): it is a deprecated no-op since PHP 8.0 (GdImage is GC'd).
            }
        }

        return $cells;
    }

    /**
     * Gutter-aware slicing. Illustrated styles (ink/etching) not only add paper gutters —
     * they draw UNEQUAL panel sizes, so fixed-ninth slicing cuts into neighbouring panels
     * (observed live). This scans for light gutter bands near the expected boundaries and
     * slices between them; when no bands are found (photoreal grids) it falls back to
     * uniform slice(). $inset still applies inside each detected cell as a safety trim.
     *
     * @return list<string> PNG bytes per cell, left-to-right, top-to-bottom
     */
    public static function sliceDetect(string $imageBytes, int $rows, int $cols, float $inset = 0.02): array
    {
        $source = @imagecreatefromstring($imageBytes);
        if ($source === false) {
            throw new RuntimeException('GridSlicer: could not decode image bytes.');
        }

        $hBands = self::gutterBands($source, horizontal: true, parts: $rows);
        $vBands = self::gutterBands($source, horizontal: false, parts: $cols);

        if ($hBands === null || $vBands === null) {
            return self::slice($imageBytes, $rows, $cols, $inset);
        }

        // Cell spans = the gaps between consecutive band edges (image edges as outer bounds).
        $rowSpans = self::spansBetween($hBands, imagesy($source));
        $colSpans = self::spansBetween($vBands, imagesx($source));

        $cells = [];
        foreach ($rowSpans as [$y1, $y2]) {
            foreach ($colSpans as [$x1, $x2]) {
                $marginX = (int) round(($x2 - $x1) * $inset);
                $marginY = (int) round(($y2 - $y1) * $inset);
                $cropW = max(1, ($x2 - $x1) - 2 * $marginX);
                $cropH = max(1, ($y2 - $y1) - 2 * $marginY);

                $cell = imagecreatetruecolor($cropW, $cropH);
                imagecopy($cell, $source, 0, 0, $x1 + $marginX, $y1 + $marginY, $cropW, $cropH);

                ob_start();
                imagepng($cell);
                $cells[] = (string) ob_get_clean();
            }
        }

        return $cells;
    }

    /**
     * Find the (parts − 1) light gutter bands along one axis, each within ±15% of its
     * expected uniform position. Returns [[start, end], …] or null when any is missing.
     *
     * @return list<array{int, int}>|null
     */
    private static function gutterBands(\GdImage $source, bool $horizontal, int $parts): ?array
    {
        $length = $horizontal ? imagesy($source) : imagesx($source);   // axis we scan along
        $breadth = $horizontal ? imagesx($source) : imagesy($source);  // axis we sample across
        $step = max(1, intdiv($breadth, 512));                          // sample ~512 px across

        // A line is "gutter" when almost none of it is dark (ink/hatching).
        $isGutterLine = function (int $pos) use ($source, $horizontal, $breadth, $step): bool {
            $dark = 0;
            $samples = 0;
            for ($i = 0; $i < $breadth; $i += $step) {
                $rgb = $horizontal ? imagecolorat($source, $i, $pos) : imagecolorat($source, $pos, $i);
                $lum = (299 * (($rgb >> 16) & 0xFF) + 587 * (($rgb >> 8) & 0xFF) + 114 * ($rgb & 0xFF)) / 1000;
                $samples++;
                if ($lum < 140) {
                    $dark++;
                }
            }

            return $samples > 0 && ($dark / $samples) < 0.03;
        };

        $bands = [];
        for ($boundary = 1; $boundary < $parts; $boundary++) {
            $expected = (int) round($length * $boundary / $parts);
            $window = (int) round($length * 0.15);

            $bestStart = null;
            $bestEnd = null;
            $runStart = null;
            for ($pos = max(0, $expected - $window); $pos <= min($length - 1, $expected + $window); $pos++) {
                if ($isGutterLine($pos)) {
                    $runStart ??= $pos;
                    continue;
                }
                if ($runStart !== null) {
                    // Keep the run whose centre lies closest to the expected boundary.
                    if ($bestStart === null || abs((($runStart + $pos) >> 1) - $expected) < abs((($bestStart + $bestEnd) >> 1) - $expected)) {
                        $bestStart = $runStart;
                        $bestEnd = $pos - 1;
                    }
                    $runStart = null;
                }
            }
            if ($runStart !== null && $bestStart === null) {
                $bestStart = $runStart;
                $bestEnd = min($length - 1, $expected + $window);
            }

            // Require a real band (≥2 lines), not a stray light scanline.
            if ($bestStart === null || ($bestEnd - $bestStart) < 1) {
                return null;
            }
            // Plausibility: printed gutters are THIN. A wide light region (bright sky on a
            // thirds horizon — which the shot prompt explicitly asks for) is scene content,
            // not a gutter; bail to uniform slicing rather than cut into a panel.
            if (($bestEnd - $bestStart) > $length * 0.06) {
                return null;
            }
            $bands[] = [$bestStart, $bestEnd];
        }

        return $bands;
    }

    /**
     * Convert boundary bands into [start, end) spans of the cells between them.
     *
     * @param  list<array{int, int}>  $bands
     * @return list<array{int, int}>
     */
    private static function spansBetween(array $bands, int $length): array
    {
        $spans = [];
        $cursor = 0;
        foreach ($bands as [$start, $end]) {
            $spans[] = [$cursor, $start];
            $cursor = $end + 1;
        }
        $spans[] = [$cursor, $length];

        return $spans;
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
