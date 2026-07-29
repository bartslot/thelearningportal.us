<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The UI languages the app ships. One source of truth: the SetLocale middleware negotiates against
 * this list, the account-settings switcher offers it, help screenshots are captured per entry, and
 * validation is derived from it — adding a language means adding a line here plus a
 * lang/{code}.json file.
 *
 * Missing keys in a language file fall back to the English source string, so a partially translated
 * language degrades to English per string instead of breaking the page. `php artisan lang:audit`
 * reports exactly what is still missing.
 */
final class Locales
{
    /**
     * code => [
     *   'name'   => the language's own name, as a speaker of it would write it,
     *   'flag'   => Blade component under resources/views/components/flags,
     *   'region' => BCP 47 tag, for <html lang> and Intl-style formatting,
     * ]
     */
    public const SUPPORTED = [
        'en' => ['name' => 'English', 'flag' => 'gb', 'region' => 'en-GB'],
        'nl' => ['name' => 'Nederlands', 'flag' => 'nl', 'region' => 'nl-NL'],
        'de' => ['name' => 'Deutsch', 'flag' => 'de', 'region' => 'de-DE'],
        'fr' => ['name' => 'Français', 'flag' => 'fr', 'region' => 'fr-FR'],
        'it' => ['name' => 'Italiano', 'flag' => 'it', 'region' => 'it-IT'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::SUPPORTED);
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::SUPPORTED);
    }

    /** For #[Validate] / rule arrays: "in:en,nl,de,fr,it". */
    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::codes());
    }

    /** The language's own name. Unknown codes return the code itself rather than blowing up. */
    public static function name(string $locale): string
    {
        return self::SUPPORTED[$locale]['name'] ?? $locale;
    }

    /** Blade flag component name, e.g. 'de' for <x-flags.de />. Falls back to the English flag. */
    public static function flag(string $locale): string
    {
        return self::SUPPORTED[$locale]['flag'] ?? 'gb';
    }

    public static function region(string $locale): string
    {
        return self::SUPPORTED[$locale]['region'] ?? $locale;
    }

    /**
     * Rows for a picker: code, name and flag together.
     *
     * @return list<array{code:string,name:string,flag:string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $code) => [
                'code' => $code,
                'name' => self::name($code),
                'flag' => self::flag($code),
            ],
            self::codes(),
        );
    }
}
