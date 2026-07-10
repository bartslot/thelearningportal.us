<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Support\GridSlicer;
use PHPUnit\Framework\TestCase;

class GridSlicerTest extends TestCase
{
    private function makeTestGrid(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        // Distinct corner colors so slicing can be verified by pixel value.
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 10, 20, 30));
        imagesetpixel($img, 0, 0, imagecolorallocate($img, 255, 0, 0));

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_slices_a_3x3_grid_into_nine_equal_cells(): void
    {
        $cells = GridSlicer::slice($this->makeTestGrid(1536, 1023), 3, 3);

        $this->assertCount(9, $cells);

        $first = imagecreatefromstring($cells[0]);
        $this->assertSame(512, imagesx($first));
        $this->assertSame(341, imagesy($first));

        // Top-left pixel of cell 0 is the red marker from the source's origin.
        $pixel = imagecolorat($first, 0, 0);
        $this->assertSame(255, ($pixel >> 16) & 0xFF);
    }

    public function test_parse_grid_accepts_valid_specs_and_rejects_garbage(): void
    {
        $this->assertSame([3, 3], GridSlicer::parseGrid('3x3'));
        $this->assertSame([2, 2], GridSlicer::parseGrid(' 2x2 '));
        $this->assertNull(GridSlicer::parseGrid('1x1'), 'A 1x1 grid is the single-image pipeline, not shots');
        $this->assertNull(GridSlicer::parseGrid(''));
        $this->assertNull(GridSlicer::parseGrid(null));
        $this->assertNull(GridSlicer::parseGrid('10x10'));
        $this->assertNull(GridSlicer::parseGrid('3by3'));
    }

    public function test_rejects_undecodable_bytes(): void
    {
        $this->expectException(\RuntimeException::class);
        GridSlicer::slice('not an image', 3, 3);
    }

    /** Grid whose cells have white gutter margins and a dark inner area (like ink-style output). */
    private function makeGutteredGrid(int $cell, int $gutter): string
    {
        $img = imagecreatetruecolor($cell * 3, $cell * 3);
        imagefilledrectangle($img, 0, 0, $cell * 3, $cell * 3, imagecolorallocate($img, 255, 255, 255));
        $dark = imagecolorallocate($img, 10, 20, 30);
        for ($row = 0; $row < 3; $row++) {
            for ($col = 0; $col < 3; $col++) {
                imagefilledrectangle(
                    $img,
                    $col * $cell + $gutter, $row * $cell + $gutter,
                    ($col + 1) * $cell - $gutter - 1, ($row + 1) * $cell - $gutter - 1,
                    $dark,
                );
            }
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function test_inset_crops_gutter_margins_off_each_cell(): void
    {
        // 100px cells with a 10px white gutter band; 10% inset must remove it entirely.
        $cells = GridSlicer::slice($this->makeGutteredGrid(100, 10), 3, 3, inset: 0.1);

        $this->assertCount(9, $cells);
        $cell = imagecreatefromstring($cells[4]);
        $this->assertSame(80, imagesx($cell));
        $this->assertSame(80, imagesy($cell));
        // Corner pixel is the dark interior, not the white gutter.
        $pixel = imagecolorat($cell, 0, 0);
        $this->assertSame(10, ($pixel >> 16) & 0xFF, 'Gutter must be cropped away');
    }

    public function test_zero_inset_keeps_full_cells(): void
    {
        $cells = GridSlicer::slice($this->makeGutteredGrid(100, 10), 3, 3);

        $cell = imagecreatefromstring($cells[0]);
        $this->assertSame(100, imagesx($cell));
        // Without inset the gutter is still there (white corner).
        $pixel = imagecolorat($cell, 0, 0);
        $this->assertSame(255, ($pixel >> 16) & 0xFF);
    }

    /**
     * Grid with UNEQUAL row heights separated by light gutter bands — what illustrated
     * styles actually render (observed live: top row 40%, middle 35%, bottom 25%).
     */
    private function makeUnequalGutteredGrid(): string
    {
        $w = 300;
        $h = 300;
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 245, 240, 225)); // paper
        $dark = imagecolorallocate($img, 30, 25, 20);

        // Rows: 0-115, 125-215, 225-300 (10px gutters at y=115..125 and y=215..225).
        // Cols: 0-95, 105-195, 205-300 (10px gutters at x=95..105 and x=195..205).
        $rowSpans = [[0, 115], [125, 215], [225, 300]];
        $colSpans = [[0, 95], [105, 195], [205, 300]];
        foreach ($rowSpans as [$y1, $y2]) {
            foreach ($colSpans as [$x1, $x2]) {
                imagefilledrectangle($img, $x1, $y1, $x2 - 1, $y2 - 1, $dark);
            }
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function test_slice_detect_finds_real_gutter_boundaries_on_unequal_rows(): void
    {
        $cells = GridSlicer::sliceDetect($this->makeUnequalGutteredGrid(), 3, 3);

        $this->assertCount(9, $cells);
        foreach ($cells as $i => $bytes) {
            $cell = imagecreatefromstring($bytes);
            // Every corner of every cell must be panel interior (dark), never gutter paper.
            foreach ([[0, 0], [imagesx($cell) - 1, 0], [0, imagesy($cell) - 1], [imagesx($cell) - 1, imagesy($cell) - 1]] as [$x, $y]) {
                $pixel = imagecolorat($cell, $x, $y);
                $this->assertLessThan(120, ($pixel >> 16) & 0xFF, "Cell $i corner ($x,$y) contains gutter paper");
            }
        }
        // Middle cell height matches its true span (~90px minus safety trim), NOT a uniform third (100px).
        $mid = imagecreatefromstring($cells[4]);
        $this->assertLessThan(95, imagesy($mid));
        $this->assertGreaterThan(60, imagesy($mid));
    }

    public function test_slice_detect_falls_back_to_uniform_slicing_when_no_gutters(): void
    {
        // Solid photoreal-style grid: no light bands → identical to plain slice().
        $cells = GridSlicer::sliceDetect($this->makeTestGrid(1536, 1023), 3, 3, inset: 0.0);

        $this->assertCount(9, $cells);
        $first = imagecreatefromstring($cells[0]);
        $this->assertSame(512, imagesx($first));
        $this->assertSame(341, imagesy($first));
    }
}
