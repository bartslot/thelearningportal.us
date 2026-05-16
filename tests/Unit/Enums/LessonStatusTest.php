<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\LessonStatus;
use Tests\TestCase;

class LessonStatusTest extends TestCase
{
    public function test_exposes_the_wizard_states_alongside_legacy_states(): void
    {
        $names = array_map(fn (LessonStatus $c) => $c->value, LessonStatus::cases());

        foreach ([
            'pending', 'generating', 'ready', 'published', 'failed',
            'draft', 'source_ready', 'outlining', 'scenes_generating',
            'scenes_ready', 'configuring', 'previewable',
        ] as $expected) {
            $this->assertContains($expected, $names, "Missing case: {$expected}");
        }
    }
}
