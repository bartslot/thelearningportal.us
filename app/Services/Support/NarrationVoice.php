<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Picks the Azure narration voice for a lesson's content language.
 *
 * The lesson's content language follows the teacher's locale (the same signal
 * LessonScriptPrompt::contentLanguage uses to write Dutch scripts for Dutch
 * teachers) — so narration and script always agree. Native voices are preferred
 * per language ("warm, calm" storytellers — founder-picked by ear 2026-07-11);
 * anything unmapped falls back to a multilingual voice rather than an
 * English voice mangling the language.
 *
 * TTS_PROVIDER_OVERRIDE_VOICE stays as an emergency pin: when set, it wins
 * over the language mapping (e.g. to test a new voice quickly).
 */
final class NarrationVoice
{
    /** Native "warm, calm" storyteller per teacher locale. */
    private const AZURE_BY_LOCALE = [
        'nl' => 'nl-NL-FennaNeural',
        'en' => 'en-US-AndrewMultilingualNeural',
    ];

    /** Speaks ~90 languages acceptably — safe for any unmapped locale. */
    private const AZURE_MULTILINGUAL_FALLBACK = 'en-US-AndrewMultilingualNeural';

    public static function azure(?string $locale, string $explicitOverride = ''): string
    {
        if ($explicitOverride !== '') {
            return $explicitOverride;
        }

        return self::AZURE_BY_LOCALE[$locale ?? ''] ?? self::AZURE_MULTILINGUAL_FALLBACK;
    }
}
