<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Picks the Azure narration voice for a lesson's content language.
 *
 * The lesson's content language follows the teacher's locale (the same signal
 * LessonScriptPrompt::contentLanguage uses to choose the script
 * language) — so narration and script always agree. Native voices are preferred
 * per language ("warm, calm" storytellers — founder-picked by ear 2026-07-11);
 * anything unmapped falls back to a multilingual voice rather than an
 * English voice mangling the language.
 *
 * TTS_PROVIDER_OVERRIDE_VOICE stays as an emergency pin: when set, it wins
 * over the language mapping (e.g. to test a new voice quickly).
 */
final class NarrationVoice
{
    /**
     * Native storyteller per language. EVERY shipped language must appear here: an unmapped one
     * falls through to the multilingual fallback below, which is an AMERICAN voice — that is how a
     * German-locale teacher's English lesson ended up read in a US accent instead of the British
     * one. Dragon HD is Azure's newest generation and is used wherever it exists for a language.
     */
    private const AZURE_BY_LOCALE = [
        'nl' => 'nl-NL-FennaNeural',
        // British on purpose. History read in a US accent kept undercutting the material.
        'en' => 'en-GB-Ollie:DragonHDLatestNeural',
        'de' => 'de-DE-Florian:DragonHDLatestNeural',
        'fr' => 'fr-FR-Remy:DragonHDLatestNeural',
        'it' => 'it-IT-Alessio:DragonHDLatestNeural',
    ];

    /**
     * Last resort for a locale we do not ship. Speaks ~90 languages acceptably, but it is American
     * English, so anything reaching it sounds wrong — keep AZURE_BY_LOCALE complete.
     */
    private const AZURE_MULTILINGUAL_FALLBACK = 'en-US-AndrewMultilingualNeural';

    public static function azure(?string $locale, string $explicitOverride = ''): string
    {
        if ($explicitOverride !== '') {
            return $explicitOverride;
        }

        return self::AZURE_BY_LOCALE[$locale ?? ''] ?? self::AZURE_MULTILINGUAL_FALLBACK;
    }

    /** Self-hosted Piper voice per locale — Dutch stays on pim; English uses a native EN voice
     *  so an English lesson isn't read in a Dutch accent. Unmapped locales fall back to Dutch. */
    private const PIPER_BY_LOCALE = [
        'nl' => 'nl_NL-pim-medium',
        'en' => 'en_US-ryan-medium',
    ];

    public static function piper(?string $locale): string
    {
        return self::PIPER_BY_LOCALE[$locale ?? ''] ?? self::PIPER_BY_LOCALE['nl'];
    }
}
