<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Support\NarrationTiming;
use PHPUnit\Framework\TestCase;

class NarrationTimingTest extends TestCase
{
    public function test_builds_canonical_entries_from_elevenlabs_parallel_arrays(): void
    {
        // Arrange — the exact shape /v1/text-to-speech/{id}/with-timestamps returns.
        $characters = ['H', 'i', '!'];
        $starts = [0.0, 0.12, 0.30];
        $ends = [0.12, 0.30, 0.44];

        // Act
        $timing = NarrationTiming::fromCharacters($characters, $starts, $ends);

        // Assert
        $this->assertSame(
            ['character' => 'H', 'start_time' => 0.0, 'end_time' => 0.12],
            $timing->toArray()[0],
        );
        $this->assertSame(3, $timing->count());
    }

    public function test_duration_is_the_last_end_time(): void
    {
        // Arrange
        $timing = NarrationTiming::fromCharacters(['a', 'b'], [0.0, 1.0], [1.0, 2.5]);

        // Act
        $duration = $timing->duration();

        // Assert — this is the number that used to be lost to a key mismatch, leaving
        // duration_seconds on a word-count estimate.
        $this->assertSame(2.5, $duration);
    }

    public function test_duration_is_zero_without_timings(): void
    {
        $this->assertSame(0.0, NarrationTiming::none()->duration());
        $this->assertTrue(NarrationTiming::none()->isEmpty());
    }

    public function test_reads_stored_alignment_back(): void
    {
        // Arrange
        $stored = [
            ['character' => 'a', 'start_time' => 0.0, 'end_time' => 0.5],
            ['character' => 'b', 'start_time' => 0.5, 'end_time' => 1.25],
        ];

        // Act
        $timing = NarrationTiming::fromStored($stored);

        // Assert
        $this->assertSame(2, $timing->count());
        $this->assertSame(1.25, $timing->duration());
    }

    public function test_treats_unrecognised_stored_shapes_as_absent(): void
    {
        // The legacy guesses — `end`, `time`, `endTime` — are not the canonical shape, and a
        // half-understood alignment is worse than none: it would place subtitles at 0.0.
        $this->assertTrue(NarrationTiming::fromStored([['character' => 'a', 'end' => 0.4]])->isEmpty());
        $this->assertTrue(NarrationTiming::fromStored(null)->isEmpty());
        $this->assertTrue(NarrationTiming::fromStored('nonsense')->isEmpty());
    }

    public function test_indexes_straight_in_when_timings_match_the_text_length(): void
    {
        // Arrange — 4 characters timed, 4 characters of text.
        $timing = NarrationTiming::fromCharacters(['a', 'b', 'c', 'd'], [0.0, 1.0, 2.0, 3.0], [1.0, 2.0, 3.0, 4.0]);

        // Act + Assert
        $this->assertSame(2.0, $timing->startAtChar(2, 4));
        $this->assertSame(0.0, $timing->startAtChar(0, 4));
    }

    public function test_scales_the_offset_when_the_spoken_text_was_respelled(): void
    {
        // Arrange — PronunciationLexicon turns "VOC" into "vee oh see", so the provider timed
        // more characters than the script holds. The last character of the script must still
        // land at the end of the audio, not clamp onto whatever entry the raw offset hits.
        $timing = NarrationTiming::fromCharacters(
            array_fill(0, 11, 'x'),
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
        );

        // Act — offset 5 of a 6-character script is the end of it.
        $start = $timing->startAtChar(5, 6);

        // Assert — the end of the audio, not entry 5 (which is halfway).
        $this->assertSame(10.0, $start);
    }

    public function test_returns_null_start_without_timings(): void
    {
        $this->assertNull(NarrationTiming::none()->startAtChar(3, 10));
    }
}
