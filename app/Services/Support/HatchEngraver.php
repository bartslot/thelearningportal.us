<?php

declare(strict_types=1);

namespace App\Services\Support;

use GdImage;
use RuntimeException;

/**
 * Deterministic two-pass engraving filter (founder direction 2026-07-10).
 *
 * Pass 1 is the generated image: it supplies FIGURES + TONE. Pass 2 draws the
 * crosshatching ourselves: luminance is quantized into bands; each band adds a
 * hatch layer at a new angle, and stroke thickness grows with darkness. The
 * source's own dark linework is then multiplied back over the hatch (hybrid),
 * so the AI provides contours while the algorithm provides a uniform, razor-
 * sharp hatch texture and an EXACT paper colour — immune to the model's messy
 * lines and yellow-drifting paper.
 *
 * Output renders at 2× the requested width internally and downsamples once,
 * which anti-aliases the strokes. Deterministic (fixed jitter seed).
 */
final class HatchEngraver
{
    /** Warm cream paper + near-black warm ink — the identity, exact by construction. */
    private const PAPER = [245, 238, 224];

    private const INK = [38, 30, 22];

    /** Hatch layers: angle (deg) + the darkness threshold that activates the layer. */
    private const LAYERS = [[22, 0.10], [-24, 0.32], [64, 0.54], [-68, 0.74]];

    private const SPACING = 13;          // px between hatch lines (supersampled canvas)

    private const SEGMENT = 10;          // walk step along each line

    private const THICKNESS_MAX = 1.8;   // extra thickness at full darkness

    private const HIGHLIGHT = 0.06;      // darkness below this stays bare paper

    private const HYSTERESIS = 0.06;     // keep a running stroke until d < t − this

    private const CONTOUR_THRESHOLD = 0.55; // Sobel magnitude that earns a contour stroke

    private const SOURCE_LINEWORK_BELOW = 0.55; // source lum under this multiplies over hatch

    /**
     * @param  string  $imageBytes  source image (any GD-decodable format)
     * @param  int  $outWidth  output width; height follows the source aspect
     * @return string PNG bytes
     */
    public static function render(string $imageBytes, int $outWidth = 1024): string
    {
        $src = @imagecreatefromstring($imageBytes);
        if ($src === false) {
            throw new RuntimeException('HatchEngraver: could not decode image bytes.');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $outH = (int) round($outWidth * $srcH / $srcW);
        $w = $outWidth * 2;
        $h = $outH * 2;

        [$coarse, $cw, $ch] = self::toneMap($src, 320, blurs: 2);
        [$fine, $fw, $fh] = self::toneMap($src, 760, blurs: 0);

        // Gentle auto-contrast from the coarse map's 10th–92nd percentile.
        $sorted = $coarse;
        sort($sorted);
        $lo = $sorted[(int) (count($sorted) * 0.10)] * 0.7;
        $range = max(0.35, $sorted[(int) (count($sorted) * 0.92)] - $lo);

        $darkAt = function (float $x, float $y) use ($coarse, $cw, $ch, $fine, $fw, $fh, $w, $h, $lo, $range): float {
            $xc = max(0, min((int) ($x / $w * $cw), $cw - 1));
            $yc = max(0, min((int) ($y / $h * $ch), $ch - 1));
            $xf = max(0, min((int) ($x / $w * $fw), $fw - 1));
            $yf = max(0, min((int) ($y / $h * $fh), $fh - 1));
            $d = 0.7 * $coarse[$yc * $cw + $xc] + 0.3 * $fine[$yf * $fw + $xf];

            return max(0.0, min(1.0, ($d - $lo) / $range));
        };

        $canvas = imagecreatetruecolor($w, $h);
        imagefilledrectangle($canvas, 0, 0, $w, $h, imagecolorallocate($canvas, ...self::PAPER));
        $inkColor = imagecolorallocate($canvas, ...self::INK);

        mt_srand(42); // deterministic output for identical input
        foreach (self::LAYERS as [$angleDeg, $threshold]) {
            self::hatchLayer($canvas, $w, $h, (float) $angleDeg, (float) $threshold, $darkAt, $inkColor);
        }
        self::contourPass($canvas, $w, $h, $coarse, $cw, $ch, $inkColor);
        self::multiplySourceLinework($canvas, $src, $w, $h);

        $final = imagescale($canvas, $outWidth, $outH); // 50% downsample = anti-aliasing
        ob_start();
        imagepng($final);

        return (string) ob_get_clean();
    }

    /** @return array{list<float>, int, int} darkness [0..1] map + dimensions */
    private static function toneMap(GdImage $src, int $width, int $blurs): array
    {
        $t = imagescale($src, $width, (int) round($width * imagesy($src) / imagesx($src)));
        imagefilter($t, IMG_FILTER_GRAYSCALE);
        for ($i = 0; $i < $blurs; $i++) {
            imagefilter($t, IMG_FILTER_GAUSSIAN_BLUR);
        }
        $h = imagesy($t);
        $w = imagesx($t);
        $map = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $map[$y * $w + $x] = 1.0 - ((imagecolorat($t, $x, $y) & 0xFF) / 255.0);
            }
        }

        return [$map, $w, $h];
    }

    /** One angle layer: parallel lines, drawn as continuous strokes with hysteresis. */
    private static function hatchLayer(GdImage $canvas, int $w, int $h, float $angleDeg, float $threshold, callable $darkAt, int $inkColor): void
    {
        $a = deg2rad($angleDeg);
        $dx = cos($a);
        $dy = sin($a);
        $nx = -$dy;
        $ny = $dx;
        $diag = sqrt($w * $w + $h * $h);
        $steps = (int) ceil($diag / self::SPACING);

        for ($i = -$steps; $i <= $steps; $i++) {
            $jitter = (mt_rand(-100, 100) / 100) * 1.4;
            $ox = $w / 2 + ($i * self::SPACING + $jitter) * $nx;
            $oy = $h / 2 + ($i * self::SPACING + $jitter) * $ny;
            $half = (int) ceil($diag / 2 / self::SEGMENT);

            $px = null;
            $py = null;
            $strokeOn = false;
            $strokeStartX = 0.0;
            $strokeStartY = 0.0;
            $sum = 0.0;
            $count = 0;
            for ($s = -$half; $s <= $half + 1; $s++) {
                $x = $ox + $s * self::SEGMENT * $dx;
                $y = $oy + $s * self::SEGMENT * $dy;
                $inside = $x >= -self::SEGMENT && $y >= -self::SEGMENT && $x <= $w + self::SEGMENT && $y <= $h + self::SEGMENT;
                $d = ($px !== null && $inside) ? $darkAt(($px + $x) / 2, ($py + $y) / 2) : 0.0;

                $keep = $strokeOn
                    ? ($d > max($threshold - self::HYSTERESIS, self::HIGHLIGHT))
                    : ($d > max($threshold, self::HIGHLIGHT));

                if ($keep && ! $strokeOn) {
                    $strokeOn = true;
                    $strokeStartX = (float) $px;
                    $strokeStartY = (float) $py;
                    $sum = 0.0;
                    $count = 0;
                }
                if ($strokeOn) {
                    $sum += $d;
                    $count++;
                }
                if (! $keep && $strokeOn) {
                    $strokeOn = false;
                    $avg = $count ? $sum / $count : $threshold;
                    $thickness = 1 + max(0, $avg - $threshold) / max(0.26, 1 - $threshold) * self::THICKNESS_MAX;
                    imagesetthickness($canvas, max(1, (int) round($thickness)));
                    imageline($canvas, (int) $strokeStartX, (int) $strokeStartY, (int) $px, (int) $py, $inkColor);
                }
                $px = $x;
                $py = $y;
            }
        }
    }

    /** Sobel edges on the coarse tone map → short strokes along the edge tangent. */
    private static function contourPass(GdImage $canvas, int $w, int $h, array $coarse, int $cw, int $ch, int $inkColor): void
    {
        imagesetthickness($canvas, 2);
        for ($y = 1; $y < $ch - 1; $y++) {
            for ($x = 1; $x < $cw - 1; $x++) {
                $gx = ($coarse[($y - 1) * $cw + $x + 1] + 2 * $coarse[$y * $cw + $x + 1] + $coarse[($y + 1) * $cw + $x + 1])
                    - ($coarse[($y - 1) * $cw + $x - 1] + 2 * $coarse[$y * $cw + $x - 1] + $coarse[($y + 1) * $cw + $x - 1]);
                $gy = ($coarse[($y + 1) * $cw + $x - 1] + 2 * $coarse[($y + 1) * $cw + $x] + $coarse[($y + 1) * $cw + $x + 1])
                    - ($coarse[($y - 1) * $cw + $x - 1] + 2 * $coarse[($y - 1) * $cw + $x] + $coarse[($y - 1) * $cw + $x + 1]);
                $mag = sqrt($gx * $gx + $gy * $gy);
                if ($mag > self::CONTOUR_THRESHOLD) {
                    $tx = -$gy / $mag;
                    $ty = $gx / $mag;
                    $cx = $x / $cw * $w;
                    $cy = $y / $ch * $h;
                    imageline(
                        $canvas,
                        (int) ($cx - $tx * 5), (int) ($cy - $ty * 5),
                        (int) ($cx + $tx * 5), (int) ($cy + $ty * 5),
                        $inkColor,
                    );
                }
            }
        }
    }

    /** Multiply the source's dark linework (figures, contours) over the hatch. */
    private static function multiplySourceLinework(GdImage $canvas, GdImage $src, int $w, int $h): void
    {
        $srcBig = imagescale($src, $w, $h);
        imagefilter($srcBig, IMG_FILTER_GRAYSCALE);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $lum = (imagecolorat($srcBig, $x, $y) & 0xFF) / 255.0;
                if ($lum < self::SOURCE_LINEWORK_BELOW) {
                    $rgb = imagecolorat($canvas, $x, $y);
                    imagesetpixel($canvas, $x, $y, imagecolorallocate(
                        $canvas,
                        max((int) ((($rgb >> 16) & 0xFF) * $lum), self::INK[0]),
                        max((int) ((($rgb >> 8) & 0xFF) * $lum), self::INK[1]),
                        max((int) (($rgb & 0xFF) * $lum), self::INK[2]),
                    ));
                }
            }
        }
    }
}
