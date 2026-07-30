<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Lesson;
use Illuminate\Support\Str;

/**
 * URLs and structured data for the public, indexable side of the site.
 *
 * Search engines need one URL per language, so the marketing pages are locale-prefixed: English
 * sits at the root (`/lessons`) and the others under their code (`/de/lessons`). Every public route
 * is therefore registered twice — see routes/web.php — and this class is the single place that knows
 * how to build either form, so canonical and hreflang tags can never drift apart.
 */
final class Seo
{
    /** Public routes, by the suffix used for both the plain and the locale-prefixed name. */
    public const PAGES = ['home', 'about', 'lessons', 'lesson', 'articles', 'article'];

    /**
     * English route names. The landing page and /about existed before the localised group, so they
     * keep their original names rather than being registered twice under a public.* alias.
     */
    private const ENGLISH_ROUTES = [
        'home' => 'home',
        'about' => 'about',
        'lessons' => 'public.lessons',
        'lesson' => 'public.lesson',
        'articles' => 'public.articles',
        'article' => 'public.article',
    ];

    /**
     * Absolute URL for a public page in a given language.
     *
     * @param  array<string,mixed>  $params
     */
    public static function url(string $page, array $params = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if ($locale === 'en') {
            return route(self::ENGLISH_ROUTES[$page] ?? "public.{$page}", $params);
        }

        return route("public.{$page}.localized", ['locale' => $locale] + $params);
    }

    /**
     * hreflang alternates: every shipped language plus x-default pointing at English.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,string>
     */
    public static function alternates(string $page, array $params = []): array
    {
        $alternates = [];

        foreach (Locales::codes() as $code) {
            $alternates[Locales::region($code)] = self::url($page, $params, $code);
        }

        $alternates['x-default'] = self::url($page, $params, 'en');

        return $alternates;
    }

    /** A lesson's public slug: readable words plus the lesson code, which keeps it unique. */
    public static function lessonSlug(Lesson $lesson): string
    {
        $words = Str::slug(Str::limit((string) ($lesson->title ?: $lesson->topic ?: 'history lesson'), 60, ''));

        return trim($words.'-'.Str::lower((string) $lesson->lesson_code), '-');
    }

    /** Pull the lesson code back out of a slug (the last dash-separated chunk). */
    public static function codeFromSlug(string $slug): string
    {
        $parts = explode('-', $slug);

        return Str::upper((string) end($parts));
    }

    /**
     * A one-sentence description for a lesson, honest about what it is.
     * Falls back through the fields that are actually populated.
     */
    public static function lessonDescription(Lesson $lesson): string
    {
        $subject = $lesson->title ?: $lesson->topic ?: __('a history topic');
        $minutes = $lesson->duration_seconds ? (int) ceil($lesson->duration_seconds / 60) : null;

        $parts = [__('A narrated history lesson about :subject.', ['subject' => $subject])];

        if ($lesson->grade_level) {
            $parts[] = __('Made for :grade.', ['grade' => $lesson->grade_level]);
        }

        if ($minutes) {
            $parts[] = __('About :minutes minutes, with a quiz.', ['minutes' => $minutes]);
        }

        $parts[] = __('Free to play in the browser, no account needed.');

        return implode(' ', $parts);
    }

    /**
     * Organisation + website graph for the landing page.
     *
     * @return array<string,mixed>
     */
    public static function organisationGraph(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => url('/').'#organization',
                    'name' => 'The Learning Portal',
                    'url' => url('/'),
                    'description' => __('Narrated, story-driven history lessons that a teacher can build in minutes and a class can play on any device.'),
                    'email' => 'info@thelearningportal.us',
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => url('/').'#website',
                    'url' => url('/'),
                    'name' => 'The Learning Portal',
                    'publisher' => ['@id' => url('/').'#organization'],
                    'inLanguage' => array_map(fn (string $c) => Locales::region($c), Locales::codes()),
                ],
            ],
        ];
    }

    /**
     * Schema.org LearningResource for one lesson.
     *
     * @return array<string,mixed>
     */
    public static function lessonGraph(Lesson $lesson): array
    {
        $graph = [
            '@context' => 'https://schema.org',
            '@type' => 'LearningResource',
            'name' => $lesson->title ?: $lesson->topic,
            'description' => self::lessonDescription($lesson),
            'url' => self::url('lesson', ['slug' => self::lessonSlug($lesson)]),
            'learningResourceType' => 'Interactive lesson',
            'educationalLevel' => $lesson->grade_level ?: null,
            'about' => $lesson->topic ?: null,
            'inLanguage' => Locales::region(app()->getLocale()),
            'isAccessibleForFree' => true,
            'provider' => ['@id' => url('/').'#organization'],
        ];

        if ($lesson->duration_seconds) {
            // ISO 8601 duration, e.g. PT12M.
            $graph['timeRequired'] = 'PT'.max(1, (int) ceil($lesson->duration_seconds / 60)).'M';
        }

        return array_filter($graph, fn ($value) => $value !== null);
    }

    /**
     * The lessons a visitor may see: published or previewable, with a code and something to play.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Lesson>
     */
    public static function publicLessons()
    {
        return Lesson::query()
            ->whereIn('status', [
                \App\Enums\LessonStatus::Published->value,
                \App\Enums\LessonStatus::Previewable->value,
            ])
            ->whereNotNull('lesson_code')
            ->whereHas('scenes', fn ($q) => $q->whereNotNull('audio_path'));
    }
}
