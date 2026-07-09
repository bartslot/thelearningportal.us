<?php

declare(strict_types=1);

namespace App\Lessons\Modules\Types;

use App\Enums\ModuleType;
use App\Lessons\Modules\Audience;
use App\Lessons\Modules\LessonModuleType;
use App\Models\LessonModule;
use Illuminate\Contracts\View\View;

/**
 * "What do you already know?" module (K-4) — activates prior knowledge before the content.
 * The slide shows the prompt question big for classroom projection; optional seed terms stay
 * hidden until the teacher reveals them.
 */
final class PriorKnowledgeModule implements LessonModuleType
{
    private const DEFAULT_DURATION_SECONDS = 60;

    /** Teachers can add at most this many seed terms. */
    public const MAX_SEED_TERMS = 5;

    public static function type(): ModuleType
    {
        return ModuleType::PriorKnowledge;
    }

    public static function label(): string
    {
        return ModuleType::PriorKnowledge->label();
    }

    public static function defaultConfig(): array
    {
        return [
            'question' => '',
            'seed_terms' => [],
        ];
    }

    public function renderEditor(LessonModule $module): View
    {
        return view('lessons.modules.prior-knowledge.editor', ['module' => $module]);
    }

    public function renderSlide(LessonModule $module, Audience $audience): View
    {
        return view('lessons.modules.prior-knowledge.slide', [
            'module' => $module,
            'audience' => $audience,
        ]);
    }

    public function estimatedDuration(LessonModule $module): int
    {
        return $module->estimated_duration_seconds ?: self::DEFAULT_DURATION_SECONDS;
    }
}
