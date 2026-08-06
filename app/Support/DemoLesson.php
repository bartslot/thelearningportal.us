<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The one lesson the landing page shows off: the hero animation types its topic, warps, and hands
 * the visitor Configure / Play for it.
 *
 * LANGUAGE IS THE HARD RULE. A visitor is offered a lesson in their own language or the English
 * one, and never a third language. This used to be picked by country alone with a "newest playable
 * lesson" fallback underneath, and on production — where neither Tasman edition had been deployed —
 * that fallback quietly handed Dutch teachers "The Silk Road" in English. A demo in the wrong
 * language is not a slightly worse demo; it is a different product.
 *
 * The visitor's language comes from two signals, browser first:
 *
 *  1. Accept-Language, when it names one of ours. This is the visitor's own setting, and it is what
 *     makes a French speaker anywhere in the world get the French lesson.
 *  2. Otherwise the country's language (config('demo.country_language')). A teacher in Amsterdam
 *     with an English laptop still teaches in Dutch.
 *
 * Resolved by title (config('demo.by_language')) rather than id, because the Canon lessons get
 * rebuilt by `lessons:compose` and come back with a new id and lesson_code. The winning lesson's
 * `language` column is then checked against the language we resolved for: a title can be edited by
 * hand, so a title match alone is not proof, and shipping the wrong language is the one failure
 * this class exists to prevent.
 */
final class DemoLesson
{
    private const CACHE_KEY = 'demo.lesson';

    private const CACHE_MINUTES = 10;

    /** The language every visitor can be served, and the only one it is acceptable to fall back to. */
    public const FALLBACK_LANGUAGE = 'en';

    /**
     * The lesson this visitor's hero should build, or null when even English has nothing playable.
     */
    public static function resolve(?Request $request = null): ?Lesson
    {
        $language = self::language($request);

        return Cache::remember(
            self::cacheKey($language),
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => self::inLanguage($language)
                ?? ($language === self::FALLBACK_LANGUAGE ? null : self::inLanguage(self::FALLBACK_LANGUAGE)),
        );
    }

    /**
     * The language this visitor's demo must be in.
     *
     * Public because the hero and the guest sandbox both need to know which language they are
     * presenting, and because it is the single decision most worth testing on its own.
     */
    public static function language(?Request $request = null): string
    {
        $request ??= request();

        $browser = self::fromBrowser($request);

        // A browser asking for a language that is NOT English is someone who went and set it, so it
        // wins outright — that is what gets a French speaker in Canada the French lesson.
        //
        // English is a weak signal by comparison. It is the out-of-the-box default on most machines
        // sold anywhere, so "en" usually means nobody chose, not "I want English". A teacher in
        // Amsterdam on a laptop that shipped in English still teaches in Dutch, and a visitor in
        // France still wants the French Revolution. So where they are gets the next say, and plain
        // English is what is left when neither signal says anything.
        if ($browser !== null && $browser !== self::FALLBACK_LANGUAGE) {
            return $browser;
        }

        return self::fromCountry($request)
            ?? $browser
            ?? self::FALLBACK_LANGUAGE;
    }

    /**
     * The learning goal the hero types out.
     *
     * It has to describe the lesson the buttons open, so it only uses the configured line when that
     * line belongs to the lesson that actually won; otherwise the lesson's own title stands in.
     * Nothing on screen may promise a lesson the visitor is not about to get — including promising
     * it in a language the lesson is not written in.
     */
    public static function goal(?Request $request = null): string
    {
        $lesson = self::resolve($request);
        if (! $lesson) {
            return (string) config('demo.lesson_goal');
        }

        $entry = self::entryFor($lesson->language ?: self::FALLBACK_LANGUAGE);

        return ($entry['title'] ?? null) === $lesson->title && ! empty($entry['goal'])
            ? (string) $entry['goal']
            : $lesson->title;
    }

    /** Hand-picked hero footage for the lesson that won, if its language entry names any. */
    public static function footage(?Request $request = null): ?string
    {
        $lesson = self::resolve($request);
        $entry = self::entryFor($lesson?->language ?: self::FALLBACK_LANGUAGE);

        return $entry['footage'] ?? config('demo.lesson_footage');
    }

    /**
     * The best playable lesson in one language — the configured one, else any other in it.
     *
     * Never reaches outside the language. That is the whole point: with no rule here, "the newest
     * playable lesson" is what a Dutch visitor got, and it was English.
     */
    private static function inLanguage(string $language): ?Lesson
    {
        $title = self::entryFor($language)['title'] ?? null;

        return self::configured($title, $language)
            ?? self::playable()->where('language', $language)->latest()->first();
    }

    /**
     * The language's configured entry, if it has one.
     *
     * English is not in `by_language`: it comes from `lesson_title` / `lesson_goal`, so
     * DEMO_LESSON_TITLE remains the single env var that pins the default demo, and English has one
     * source of truth rather than two that can disagree.
     */
    private static function entryFor(?string $language): array
    {
        if ($language === self::FALLBACK_LANGUAGE) {
            return [
                'title' => config('demo.lesson_title'),
                'goal' => config('demo.lesson_goal'),
                'footage' => config('demo.lesson_footage'),
            ];
        }

        $map = (array) config('demo.by_language', []);

        return $language && isset($map[$language]) ? (array) $map[$language] : [];
    }

    /** The Accept-Language header's first preference among the languages we ship, if any. */
    private static function fromBrowser(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language');
        if ($header === '') {
            return null;
        }

        // Symfony's getPreferredLanguage() returns the FIRST item of the list it was given when the
        // browser matches none of them, so on its own it cannot tell "asked for English" apart from
        // "asked for nothing we have" — and that difference decides whether the country gets a say.
        // So read the header directly.
        foreach (explode(',', $header) as $tag) {
            $code = strtolower(substr(trim(explode(';', $tag)[0]), 0, 2));

            if (Locales::isSupported($code)) {
                return $code;
            }
        }

        return null;
    }

    /** The language the visitor's country implies. */
    private static function fromCountry(Request $request): ?string
    {
        $country = VisitorCountry::code($request);
        $language = $country ? (((array) config('demo.country_language'))[$country] ?? null) : null;

        return Locales::isSupported($language) ? $language : null;
    }

    private static function cacheKey(string $language): string
    {
        return self::CACHE_KEY.'.'.$language;
    }

    /**
     * The lesson named in config/demo.php — trusted because somebody chose it deliberately, but
     * only once its `language` column agrees.
     *
     * Status is NOT checked here, only that it can be played. Merely opening a lesson in the
     * Configure editor sets its status to `configuring` (Step3SceneConfigurator::mount), so a
     * status test meant the hand-picked demo lesson silently vanished from the landing page the
     * first time anyone looked at it in the wizard. The public-status rule still guards the
     * fallback below, which nobody chose.
     */
    private static function configured(?string $title, string $language): ?Lesson
    {
        if (! $title) {
            return null;
        }

        return Lesson::query()
            ->where('title', $title)
            ->where('language', $language)
            ->whereNotNull('lesson_code')
            ->whereHas('scenes')
            ->whereDoesntHave('scenes', fn ($scenes) => $scenes->where('status', '!=', 'ready'))
            ->first();
    }

    /**
     * Lessons a visitor can actually open: public status, a code to open it by, and scenes that are
     * all finished.
     *
     * Deliberately NOT "has a narrated scene", which is what the landing carousel asks. A voyage
     * lesson built by `lessons:make-voyage` is a map tour: every scene is ready and none of them is
     * narrated, so a narration test would reject a lesson that plays perfectly. What actually makes
     * a lesson unopenable is a scene still waiting on the generation pipeline.
     */
    private static function playable()
    {
        return Lesson::query()
            ->whereIn('status', [LessonStatus::Published->value, LessonStatus::Previewable->value])
            ->whereNotNull('lesson_code')
            ->whereHas('scenes')
            ->whereDoesntHave('scenes', fn ($scenes) => $scenes->where('status', '!=', 'ready'));
    }

    /** Drop every language's cached pick — a lesson save can change any of them. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY); // pre-language key, still worth clearing

        foreach (Locales::codes() as $code) {
            Cache::forget(self::cacheKey($code));
        }
    }
}
