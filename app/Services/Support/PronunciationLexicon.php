<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Per-language pronunciation fixes applied to narration text JUST before TTS.
 *
 * TTS voices read loanwords in their own phonetics — the Dutch voices read the
 * Roman "limes" as English "laims" (founder catch 2026-07-13). The lexicon
 * substitutes a phonetic respelling in the SPOKEN text only; the script text
 * shown on screen/PDF is untouched. Plain-text level, so it works identically
 * across Azure, edge-tts and ElevenLabs.
 *
 * Note: on providers that return character alignment (ElevenLabs), a respelled
 * word can no longer match a shot anchor_sentence verbatim — anchor resolution
 * then falls back to the even-split timing that already ships.
 */
final class PronunciationLexicon
{
    /** locale => [written form => spoken respelling]. Word-boundary matched, case preserved. */
    private const LEXICON = [
        'nl' => [
            'limes' => 'liemes',
        ],
    ];

    public static function apply(string $text, ?string $locale): string
    {
        $entries = self::LEXICON[$locale ?? ''] ?? [];

        foreach ($entries as $written => $spoken) {
            $text = (string) preg_replace_callback(
                '/\b'.preg_quote($written, '/').'\b/iu',
                fn (array $m) => self::matchCase($m[0], $spoken),
                $text,
            );
        }

        return $text;
    }

    /** Carry the original word's casing onto the respelling (Limes → Liemes, LIMES → LIEMES). */
    private static function matchCase(string $original, string $replacement): string
    {
        if (mb_strtoupper($original) === $original) {
            return mb_strtoupper($replacement);
        }
        if (mb_strtoupper(mb_substr($original, 0, 1)) === mb_substr($original, 0, 1)) {
            return mb_strtoupper(mb_substr($replacement, 0, 1)).mb_substr($replacement, 1);
        }

        return $replacement;
    }
}
