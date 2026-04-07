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
}
