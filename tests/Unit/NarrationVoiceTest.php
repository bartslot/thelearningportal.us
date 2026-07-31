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

    /**
     * British, deliberately. This test used to assert the American multilingual voice, because that
     * is what English fell back to before the map was completed — history read in a US accent, which
     * is precisely the thing we now forbid. It asserted the bug.
     */
    public function test_english_lessons_get_a_british_narrator(): void
    {
        $this->assertSame('en-GB-Ollie:DragonHDLatestNeural', NarrationVoice::azure('en'));
    }

    public function test_every_shipped_language_gets_its_own_native_narrator(): void
    {
        // French used to reach the American fallback here too. A shipped language arriving at that
        // voice is a bug, not a fallback working.
        $this->assertSame('fr-FR-Remy:DragonHDLatestNeural', NarrationVoice::azure('fr'));
        $this->assertSame('de-DE-Florian:DragonHDLatestNeural', NarrationVoice::azure('de'));
        $this->assertSame('it-IT-Alessio:DragonHDLatestNeural', NarrationVoice::azure('it'));
    }

    public function test_only_a_language_we_do_not_ship_reaches_the_multilingual_fallback(): void
    {
        $this->assertSame('en-US-AndrewMultilingualNeural', NarrationVoice::azure('sv'));
        $this->assertSame('en-US-AndrewMultilingualNeural', NarrationVoice::azure(null));
    }

    public function test_explicit_env_pin_always_wins(): void
    {
        $this->assertSame('nl-NL-MaartenNeural', NarrationVoice::azure('nl', 'nl-NL-MaartenNeural'));
        $this->assertSame('nl-NL-MaartenNeural', NarrationVoice::azure(null, 'nl-NL-MaartenNeural'));
    }
}
