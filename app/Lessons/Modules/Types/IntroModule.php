<?php

declare(strict_types=1);

namespace App\Lessons\Modules\Types;

use App\Enums\ModuleType;
use App\Lessons\Modules\Audience;
use App\Lessons\Modules\LessonModuleType;
use App\Models\LessonModule;
use Illuminate\Contracts\View\View;

/** Intro module — the opening framing slide (title, subheading, objectives). */
final class IntroModule implements LessonModuleType
{
    private const DEFAULT_DURATION_SECONDS = 20;

    public static function type(): ModuleType
    {
        return ModuleType::Intro;
    }

    public static function label(): string
    {
        return ModuleType::Intro->label();
    }

    public static function defaultConfig(): array
    {
        return [
            'heading' => '',
            'subheading' => '',
            'objectives' => [],
        ];
    }

    public function renderEditor(LessonModule $module): View
    {
        return view('lessons.modules.intro.editor', ['module' => $module]);
    }

    public function renderSlide(LessonModule $module, Audience $audience): View
    {
        return view('lessons.modules.intro.slide', [
            'module' => $module,
            'audience' => $audience,
        ]);
    }

    public function estimatedDuration(LessonModule $module): int
    {
        return $module->estimated_duration_seconds ?: self::DEFAULT_DURATION_SECONDS;
    }
}
