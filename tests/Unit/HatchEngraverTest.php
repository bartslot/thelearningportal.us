<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Support\HatchEngraver;
use PHPUnit\Framework\TestCase;

class HatchEngraverTest extends TestCase
{
    /** Left half dark, right half near-white — tone must become hatch density. */
    private function makeToneTestImage(): string
    {
        $img = imagecreatetruecolor(400, 260);
        imagefilledrectangle($img, 0, 0, 199, 259, imagecolorallocate($img, 55, 50, 45));
        imagefilledrectangle($img, 200, 0, 399, 259, imagecolorallocate($img, 250, 248, 244));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function inkFraction(string $pngBytes, int $x1, int $x2): float
    {
        $img = imagecreatefromstring($pngBytes);
        $h = imagesy($img);
        $ink = 0;
        $total = 0;
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = $x1; $x < $x2; $x += 2) {
                $lum = (imagecolorat($img, $x, $y) >> 8) & 0xFF; // green channel proxy
                $total++;
                if ($lum < 120) {
                    $ink++;
                }
            }
        }

        return $total ? $ink / $total : 0.0;
    }

    public function test_dark_areas_get_dense_hatching_and_highlights_stay_paper(): void
    {
        $out = HatchEngraver::render($this->makeToneTestImage(), outWidth: 400);

        $img = imagecreatefromstring($out);
        $this->assertSame(400, imagesx($img));
        $this->assertSame(260, imagesy($img));

        $darkSide = $this->inkFraction($out, 20, 180);
        $lightSide = $this->inkFraction($out, 220, 380);
        $this->assertGreaterThan(0.25, $darkSide, 'Dark tone must produce dense hatching');
        $this->assertLessThan($darkSide / 3, $lightSide, 'Highlights must stay mostly bare paper');
    }

    public function test_output_is_deterministic_for_identical_input(): void
    {
        $src = $this->makeToneTestImage();

        $this->assertSame(
            md5(HatchEngraver::render($src, 300)),
            md5(HatchEngraver::render($src, 300)),
        );
    }

    public function test_rejects_undecodable_bytes(): void
    {
        $this->expectException(\RuntimeException::class);
        HatchEngraver::render('not an image');
    }
}
