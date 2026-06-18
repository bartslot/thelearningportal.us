<?php

declare(strict_types=1);

namespace App\Lessons\Modules;

use App\Enums\ModuleType;
use App\Lessons\Modules\Types\IntroModule;
use App\Lessons\Modules\Types\QuizMcqModule;
use InvalidArgumentException;

/**
 * Maps a ModuleType to its behaviour implementation. The Composer palette and the Player both
 * read this — it is the single source of truth for "which types are buildable today".
 *
 * Add a case here when a new module type ships (see epic-k-lesson-composer-plan.md §5).
 */
final class ModuleRegistry
{
    /**
     * @return class-string<LessonModuleType>|null
     */
    private static function classFor(ModuleType $type): ?string
    {
        return match ($type) {
            ModuleType::Intro => IntroModule::class,
            ModuleType::QuizMcq => QuizMcqModule::class,
            default => null,
        };
    }

    public static function for(ModuleType $type): LessonModuleType
    {
        $class = self::classFor($type);

        if ($class === null) {
            throw new InvalidArgumentException("No module implementation registered for type [{$type->value}].");
        }

        return new $class;
    }

    public static function has(ModuleType $type): bool
    {
        return self::classFor($type) !== null;
    }

    /**
     * Every type that can be added/rendered today.
     *
     * @return list<ModuleType>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            ModuleType::cases(),
            static fn (ModuleType $type): bool => self::has($type),
        ));
    }
}
