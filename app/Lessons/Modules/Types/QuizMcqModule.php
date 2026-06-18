<?php

declare(strict_types=1);

namespace App\Lessons\Modules\Types;

use App\Enums\ModuleType;
use App\Lessons\Modules\Audience;
use App\Lessons\Modules\LessonModuleType;
use App\Models\LessonModule;
use Illuminate\Contracts\View\View;

/** Multiple-choice quiz module. Content lives inline in `config` (decision D4). */
final class QuizMcqModule implements LessonModuleType
{
    private const DEFAULT_DURATION_SECONDS = 30;

    public static function type(): ModuleType
    {
        return ModuleType::QuizMcq;
    }

    public static function label(): string
    {
        return ModuleType::QuizMcq->label();
    }

    public static function defaultConfig(): array
    {
        return [
            'question' => '',
            'answers' => [
                ['label' => 'A', 'text' => '', 'is_correct' => false],
                ['label' => 'B', 'text' => '', 'is_correct' => false],
                ['label' => 'C', 'text' => '', 'is_correct' => false],
                ['label' => 'D', 'text' => '', 'is_correct' => false],
            ],
            'feedback_correct' => '',
            'feedback_wrong' => '',
            'teacher_note' => '',
        ];
    }

    public function renderEditor(LessonModule $module): View
    {
        return view('lessons.modules.quiz-mcq.editor', ['module' => $module]);
    }

    public function renderSlide(LessonModule $module, Audience $audience): View
    {
        return view('lessons.modules.quiz-mcq.slide', [
            'module' => $module,
            'audience' => $audience,
        ]);
    }

    public function estimatedDuration(LessonModule $module): int
    {
        return $module->estimated_duration_seconds ?: self::DEFAULT_DURATION_SECONDS;
    }
}
