<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Resolves help-centre screenshots per language.
 *
 * Screenshots are captured from the running app by `php artisan help:screenshots`, one set per
 * language, into public/help/{locale}/{key}.png — so a German teacher sees a German interface in
 * the picture rather than an English one they then have to translate in their head.
 *
 * A language whose set has not been captured yet falls back to English, and a key with no image at
 * all resolves to null so the help page simply renders without a picture.
 */
final class HelpMedia
{
    /**
     * Not `help`: a public/help directory shadows the /help route, because the web server serves a
     * matching directory before Laravel ever sees the request.
     */
    private const DIRECTORY = 'help-media';

    private const EXTENSION = '.png';

    /** Screenshots the capture command produces: key => the page it shoots. */
    public const SHOTS = [
        'dashboard' => 'teacher.dashboard',
        'new-lesson' => 'teacher.lessons.chat',
        'wizard-steps' => 'teacher.lessons.create',
        'wizard-configure' => 'teacher.lessons.wizard',
        'wizard-play' => 'teacher.lessons.wizard',
        'lessons' => 'teacher.lessons.index',
        'classes' => 'teacher.classes.index',
        'results' => 'teacher.results.hub',
        'timemap' => 'teacher.timemap',
        'settings' => 'settings.index',
    ];

    /** Public URL of a screenshot in the given language, or null when nothing has been captured. */
    public static function url(?string $key, ?string $locale = null): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $locale ??= app()->getLocale();

        foreach ([$locale, 'en'] as $candidate) {
            $relative = self::DIRECTORY."/{$candidate}/{$key}".self::EXTENSION;

            if (File::exists(public_path($relative))) {
                // Cache-bust on recapture: the filename never changes, only its contents.
                return asset($relative).'?v='.File::lastModified(public_path($relative));
            }
        }

        return null;
    }

    /** Where the capture command writes a language's set. */
    public static function directoryFor(string $locale): string
    {
        return public_path(self::DIRECTORY."/{$locale}");
    }

    /** Languages that already have at least one screenshot on disk. */
    public static function capturedLocales(): array
    {
        return array_values(array_filter(
            Locales::codes(),
            fn (string $code) => File::isDirectory(self::directoryFor($code))
                && File::files(self::directoryFor($code)) !== [],
        ));
    }
}
