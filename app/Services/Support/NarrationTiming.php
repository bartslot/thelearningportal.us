<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * The one shape narration timings have, everywhere.
 *
 * Providers hand back timings in their own formats — ElevenLabs returns three parallel
 * character arrays, edge-tts returns a flat list of word spans — and for months each one
 * was stored raw. Consumers then guessed at the keys (`end` ?? `time` ?? `endTime`), and a
 * guess that missed produced no error, just a silently worse subtitle. Every timing now
 * passes through here first, so there is exactly one shape to read:
 *
 *     [{ character: string, start_time: float, end_time: float }, ...]
 *
 * one entry per character of the SPOKEN text, in order. That is the shape
 * `scenes.audio_alignment` stores and the shape the player's captions.js, shot-sync.js and
 * avatar-3d.js already expect.
 */
final class NarrationTiming
{
    /** @param list<array{character: string, start_time: float, end_time: float}> $entries */
    private function __construct(private readonly array $entries) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * ElevenLabs `/with-timestamps`: three parallel arrays, one slot per character.
     *
     * @param  array<int, string>  $characters
     * @param  array<int, float|int|string>  $starts
     * @param  array<int, float|int|string>  $ends
     */
    public static function fromCharacters(array $characters, array $starts, array $ends): self
    {
        $entries = [];

        foreach (array_values($characters) as $i => $character) {
            $start = (float) ($starts[$i] ?? 0.0);
            $entries[] = [
                'character' => (string) $character,
                'start_time' => $start,
                'end_time' => (float) ($ends[$i] ?? $start),
            ];
        }

        return new self($entries);
    }

    /**
     * Read back whatever is in `scenes.audio_alignment`. Anything that isn't a list of
     * canonical entries is treated as absent rather than half-trusted.
     */
    public static function fromStored(mixed $stored): self
    {
        if (! is_array($stored)) {
            return self::none();
        }

        $entries = [];
        foreach (array_values($stored) as $entry) {
            if (! is_array($entry) || ! array_key_exists('start_time', $entry)) {
                continue;
            }
            $start = (float) $entry['start_time'];
            $entries[] = [
                'character' => (string) ($entry['character'] ?? ''),
                'start_time' => $start,
                'end_time' => (float) ($entry['end_time'] ?? $start),
            ];
        }

        return new self($entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** Where the narration actually stops, in seconds — 0.0 when there are no timings. */
    public function duration(): float
    {
        if ($this->entries === []) {
            return 0.0;
        }

        return (float) $this->entries[$this->count() - 1]['end_time'];
    }

    /**
     * Start time of the character at `$offset` in a text of `$totalChars` characters.
     *
     * Usually the timings run one-per-character over that very text and the offset indexes
     * straight in. They can be a little shorter or longer, though: PronunciationLexicon
     * respells words for the voice ("VOC" is spoken "vee oh see"), so the spoken text the
     * provider timed is not always the same length as the script being indexed. Scaling by
     * position keeps a late sentence late instead of clamping every offset past the end onto
     * the final character.
     */
    public function startAtChar(int $offset, int $totalChars): ?float
    {
        if ($this->entries === []) {
            return null;
        }

        $last = $this->count() - 1;
        $index = $this->count() === $totalChars || $totalChars <= 1
            ? $offset
            : (int) round($offset * $last / ($totalChars - 1));

        return (float) $this->entries[max(0, min($index, $last))]['start_time'];
    }

    /** @return list<array{character: string, start_time: float, end_time: float}> */
    public function toArray(): array
    {
        return $this->entries;
    }
}
