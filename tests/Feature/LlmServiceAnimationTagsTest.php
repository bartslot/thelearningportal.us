<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class LlmServiceAnimationTagsTest extends TestCase
{
    public function test_system_prompt_contains_animation_tag_instructions(): void
    {
        // We can't easily test the prompt in isolation without refactoring,
        // so we verify the service source contains the expected strings.
        // This is a smoke test to catch accidental deletions.
        $source = file_get_contents(app_path('Services/LlmService.php'));

        $this->assertStringContainsString('[walk]', $source);
        $this->assertStringContainsString('[excited]', $source);
        $this->assertStringContainsString('[serious]', $source);
        $this->assertStringContainsString('[whisper]', $source);
        $this->assertStringContainsString('[point]', $source);
        $this->assertStringContainsString('[nod]', $source);
        $this->assertStringContainsString('[gesture]', $source);
        $this->assertStringContainsString('ANIMATION TAGS', $source);
    }
}
