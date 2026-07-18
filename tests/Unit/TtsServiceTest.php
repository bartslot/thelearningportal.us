<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TtsService;
use Tests\TestCase;

class TtsServiceTest extends TestCase
{
    public function test_prepare_speech_text_strips_markdown_headers_and_labels(): void
    {
        $service = new TtsService();

        $input = <<<TEXT
## Opening Hook
I am speaking as Cleopatra.

**[Key Event 1: Ancient Egyptian Society]**
The kingdom was shaped by trade, power, and culture.

## Reflection
What can we learn today?
TEXT;

        $output = $service->prepareSpeechText($input);

        $this->assertStringNotContainsString('Opening Hook', $output);
        $this->assertStringNotContainsString('Key Event 1', $output);
        $this->assertStringContainsString('I am speaking as Cleopatra.', $output);
        $this->assertStringContainsString('The kingdom was shaped by trade, power, and culture.', $output);
        $this->assertStringContainsString('What can we learn today?', $output);
    }

    public function test_single_newline_is_soft_but_blank_line_is_a_paragraph_break(): void
    {
        $service = new TtsService();

        // Soft newline (single \n) within a thought → one continuous topic (space, no break).
        // Blank line (\n\n) between thoughts → a real paragraph break (kept as \n\n for TTS).
        $input = "First line\nsecond line of the same idea.\n\nA brand new topic entirely.";

        $output = $service->prepareSpeechText($input);

        $this->assertSame(
            "First line second line of the same idea.\n\nA brand new topic entirely.",
            $output,
        );
        // Exactly one paragraph boundary (the blank line), not two.
        $this->assertSame(1, substr_count($output, "\n\n"));
    }
}
