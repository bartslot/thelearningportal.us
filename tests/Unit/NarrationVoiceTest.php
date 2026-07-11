<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Support\NarrationVoice;
use PHPUnit\Framework\TestCase;

class NarrationVoiceTest extends TestCase
{
    public function test_dutch_lessons_get_the_native_dutch_narrator(): void
    {
        $this->assertSame('nl-NL-FennaNeural', NarrationVoice::azure('nl'));
    }

    public function test_english_lessons_get_an_english_narrator(): void
    {
        $this->assertSame('en-US-AndrewMultilingualNeural', NarrationVoice::azure('en'));
    }

    public function test_unmapped_or_missing_locale_falls_back_to_a_multilingual_voice(): void
    {
        $this->assertSame('en-US-AndrewMultilingualNeural', NarrationVoice::azure('fr'));
        $this->assertSame('en-US-AndrewMultilingualNeural', NarrationVoice::azure(null));
    }

    public function test_explicit_env_pin_always_wins(): void
    {
        $this->assertSame('nl-NL-MaartenNeural', NarrationVoice::azure('nl', 'nl-NL-MaartenNeural'));
        $this->assertSame('nl-NL-MaartenNeural', NarrationVoice::azure(null, 'nl-NL-MaartenNeural'));
    }
}
