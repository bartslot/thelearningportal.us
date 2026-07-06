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
}
