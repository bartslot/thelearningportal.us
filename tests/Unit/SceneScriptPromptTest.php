<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SceneScriptPrompt;
use PHPUnit\Framework\TestCase;

class SceneScriptPromptTest extends TestCase
{
    public function test_system_prompt_no_longer_anchors_on_you_are_standing(): void
    {
        $system = SceneScriptPrompt::system();

        // The original bug used "you are standing…" as the second-person EXAMPLE, anchoring the model.
        $this->assertStringNotContainsString('second person ("you are standing', $system);
        // It is now an explicit negative instruction + a variety directive instead.
        $this->assertStringContainsString('never begin with "you are standing"', $system);
        $this->assertStringContainsString('VARY', $system);
    }

    public function test_opening_hint_rotates_by_scene_order(): void
    {
        $first = SceneScriptPrompt::openingHintFor(1);
        $second = SceneScriptPrompt::openingHintFor(2);

        $this->assertNotSame($first, $second, 'consecutive scenes must get different opening styles');
        // Wraps after the style count (5), so scene 6 matches scene 1.
        $this->assertSame($first, SceneScriptPrompt::openingHintFor(6));
    }
}
