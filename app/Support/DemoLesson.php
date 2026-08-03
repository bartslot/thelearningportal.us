<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Support\Facades\Cache;

/**
 * The one lesson the landing page shows off: the hero animation types its topic, warps, and hands
 * the visitor Configure / Play for it.
 *
 * Resolved by title (config('demo.lesson_title')) rather than id, because the Canon lessons get
 * rebuilt by `lessons:compose` and come back with a new id and lesson_code. If that title is not
 * playable right now, the newest lesson that IS playable stands in — a hero with a dead button is
 * worse than a hero pointing at a different lesson.
 */
final class DemoLesson
{
    private const CACHE_KEY = 'demo.lesson';

    private const CACHE_MINUTES = 10;

    /**
     * The lesson this visitor's hero should build.
     *
     * Country-aware: a German visitor meets a lesson from their own curriculum rather than a
     * Dutch one (config('demo.by_country'), keyed by App\Support\VisitorCountry). Falls through
     * to the configured default, then to the newest playable lesson, so a country listed before
     * its lesson has been written costs nothing.
     */
    public static function resolve(): ?Lesson
    {
        $country = VisitorCountry::code();

        return Cache::remember(
            self::cacheKey($country),
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => self::byTitle(self::titleFor($country))
                ?? self::byTitle(config('demo.lesson_title'))
                ?? self::query()->latest()->first(),
        );
    }

    /**
     * The learning goal the hero types out.
     *
     * It has to describe the lesson the buttons open, so it only uses the configured line when
     * that line belongs to the lesson that actually won; otherwise the lesson's own title stands
     * in. Nothing on screen may promise a lesson the visitor is not about to get.
     */
    public static function goal(): string
    {
        $lesson = self::resolve();
        $country = VisitorCountry::code();
        $entry = self::entryFor($country);

        if ($lesson && $entry && ($entry['title'] ?? null) === $lesson->title) {
            return (string) ($entry['goal'] ?? $lesson->title);
        }

        if ($lesson && $lesson->title === config('demo.lesson_title')) {
            return (string) config('demo.lesson_goal');
        }

        return (string) ($lesson?->title ?? config('demo.lesson_goal'));
    }

    /** The country's configured entry, if it has one. */
    private static function entryFor(?string $country): ?array
    {
        $map = (array) config('demo.by_country', []);

        return $country && isset($map[$country]) ? (array) $map[$country] : null;
    }

    private static function titleFor(?string $country): ?string
    {
        return self::entryFor($country)['title'] ?? null;
    }

    private static function cacheKey(?string $country): string
    {
        return self::CACHE_KEY.'.'.($country ?: 'default');
    }

    /** A playable lesson with this exact title, whatever state its editor left it in. */
    private static function byTitle(?string $title): ?Lesson
    {
        return $title ? self::configured($title) : null;
    }

    /**
     * The lesson named in config/demo.php — trusted because somebody chose it deliberately.
     *
     * Status is NOT checked here, only that it can be played. Merely opening a lesson in the
     * Configure editor sets its status to `configuring` (Step3SceneConfigurator::mount), so a
     * status test meant the hand-picked demo lesson silently vanished from the landing page the
     * first time anyone looked at it in the wizard, and the hero quietly showed a different
     * lesson instead. The public-status rule still guards the FALLBACK below, which nobody chose.
     */
    private static function configured(?string $title = null): ?Lesson
    {
        return Lesson::query()
            ->where('title', $title ?? config('demo.lesson_title'))
            ->whereNotNull('lesson_code')
            ->whereHas('scenes')
            ->whereDoesntHave('scenes', fn ($scenes) => $scenes->where('status', '!=', 'ready'))
            ->first();
    }

    /**
     * Lessons a visitor can actually open: public status, a code to open it by, and scenes that
     * are all finished.
     *
     * Deliberately NOT "has a narrated scene", which is what the landing carousel asks. A voyage
     * lesson built by `lessons:make-voyage` is a map tour: every scene is ready and none of them
     * is narrated, so a narration test would reject a lesson that plays perfectly. What actually
     * makes a lesson unopenable is a scene still waiting on the generation pipeline.
     */
    private static function query()
    {
        return Lesson::query()
            ->whereIn('status', [LessonStatus::Published->value, LessonStatus::Previewable->value])
            ->whereNotNull('lesson_code')
            ->whereHas('scenes')
            ->whereDoesntHave('scenes', fn ($scenes) => $scenes->where('status', '!=', 'ready'));
    }

    /** Drop every country's cached pick — a lesson save can change any of them. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY); // pre-country key, still worth clearing
        Cache::forget(self::cacheKey(null));

        foreach (array_keys((array) config('demo.by_country', [])) as $country) {
            Cache::forget(self::cacheKey((string) $country));
        }
    }
}
