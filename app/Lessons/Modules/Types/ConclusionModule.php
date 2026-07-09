<?php

declare(strict_types=1);

namespace App\Lessons\Modules\Types;

use App\Enums\ModuleType;
use App\Lessons\Modules\Audience;
use App\Lessons\Modules\LessonModuleType;
use App\Models\Lesson;
use App\Models\LessonModule;
use Illuminate\Contracts\View\View;

/**
 * Conclusion module (K-6) — the closing recap slide: 3-5 takeaway lines plus an optional
 * "what's next" pointer (ties to backlog G2). Takeaways default from the lesson's learning
 * objectives when the lesson has an outline.
 */
final class ConclusionModule implements LessonModuleType
{
    private const DEFAULT_DURATION_SECONDS = 45;

    /** Teachers can list at most this many takeaway lines. */
    public const MAX_TAKEAWAYS = 5;

    public static function type(): ModuleType
    {
        return ModuleType::Conclusion;
    }

    public static function label(): string
    {
        return ModuleType::Conclusion->label();
    }

    public static function defaultConfig(): array
    {
        return [
            'heading' => '',
            'takeaways' => [],
            'whats_next' => '',
        ];
    }

    /**
     * Default config seeded from the lesson: takeaway lines are prefilled from the lesson's
     * learning objectives (outline, backlog B3) when present. Returns a new array — never
     * mutates. The Composer calls this when a fresh Conclusion module is added.
     */
    public static function configWithLessonDefaults(Lesson $lesson): array
    {
        $objectives = collect($lesson->outline['learning_objectives'] ?? [])
            ->map(static fn (mixed $objective): string => is_array($objective)
                ? trim((string) ($objective['text'] ?? ''))
                : trim((string) $objective))
            ->filter(static fn (string $text): bool => $text !== '')
            ->take(self::MAX_TAKEAWAYS)
            ->values()
            ->all();

        return array_merge(self::defaultConfig(), ['takeaways' => $objectives]);
    }

    public function renderEditor(LessonModule $module): View
    {
        return view('lessons.modules.conclusion.editor', ['module' => $module]);
    }

    public function renderSlide(LessonModule $module, Audience $audience): View
    {
        return view('lessons.modules.conclusion.slide', [
            'module' => $module,
            'audience' => $audience,
        ]);
    }

    public function estimatedDuration(LessonModule $module): int
    {
        return $module->estimated_duration_seconds ?: self::DEFAULT_DURATION_SECONDS;
    }
}
