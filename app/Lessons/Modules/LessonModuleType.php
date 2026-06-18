<?php

declare(strict_types=1);

namespace App\Lessons\Modules;

use App\Enums\ModuleType;
use App\Models\LessonModule;
use Illuminate\Contracts\View\View;

/**
 * The contract every lesson-module type implements so the Composer (K-2) and the Player (K-3)
 * can treat all module types uniformly. Register implementations in ModuleRegistry.
 */
interface LessonModuleType
{
    /** The enum case this implementation handles. */
    public static function type(): ModuleType;

    /** Teacher-facing label (localizable). */
    public static function label(): string;

    /** The `config` shape used when a fresh module of this type is added. */
    public static function defaultConfig(): array;

    /** Composer (edit) view for a single module. */
    public function renderEditor(LessonModule $module): View;

    /** Player (present) view for a single module; $audience hides answers/notes for students. */
    public function renderSlide(LessonModule $module, Audience $audience): View;

    /** Estimated duration in seconds (for the composer's per-module readout). */
    public function estimatedDuration(LessonModule $module): int;
}
