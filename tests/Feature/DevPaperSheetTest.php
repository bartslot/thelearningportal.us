<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DevPaperSheet;
use Tests\TestCase;

class DevPaperSheetTest extends TestCase
{
    public function test_renders_a_valid_png_for_the_given_sheets(): void
    {
        $png = app(DevPaperSheet::class)->png([
            ['name' => 'Emma V.', 'answers' => ['A', 'B', 'C', 'D', 'A', 'B']],
            ['name' => 'Liam B.', 'answers' => ['A', 'A', 'C', 'C', 'A', 'A']],
        ], 6);

        $this->assertNotSame('', $png);
        // PNG magic bytes.
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));

        $meta = getimagesizefromstring($png);
        $this->assertNotFalse($meta);
        $this->assertSame(IMAGETYPE_PNG, $meta[2]);
        $this->assertSame(920, $meta[0]);       // fixed width
        $this->assertGreaterThan(400, $meta[1]); // two stacked sheets => tall
    }

    public function test_handles_missing_answers_without_error(): void
    {
        $png = app(DevPaperSheet::class)->png([
            ['name' => 'No Answers', 'answers' => []],
        ], 4);

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    }
}
