<?php

declare(strict_types=1);

namespace App\Lessons;

use App\Enums\NarrativeFramework;
use App\Services\Support\ImageStyleTemplate;
use InvalidArgumentException;

/**
 * A declarative lesson-type preset (K-13): the sensible defaults a named lesson type carries so
 * teachers — and the K-12 creation chat — configure almost nothing. Pure value object; the
 * catalog lives in LessonPresets. `apply()` turns a preset into Lesson::create attributes.
 */
final readonly class LessonPreset
{
    /** game_type values a preset may carry (strategy/debate stay wizard-only for now). */
    public const GAME_TYPES = ['quiz', 'story_game'];

    /** Valid quiz_timing strings (see StoreWizardSettingsRequest: during|after|both). */
    public const QUIZ_TIMINGS = ['during', 'after', 'both'];

    /**
     * @param string $label English label; passed through __() at render time.
     * @param string $description One teacher-facing sentence.
     * @param string $narrativeFramework A NarrativeFramework case value.
     * @param array<string, mixed> $gameConfigDefaults Seed for the lessons.game_config JSON column.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $narrativeFramework,
        public bool $includeGame,
        public ?string $gameType,
        public array $gameConfigDefaults,
        public int $quizQuestionCount,
        public string $quizTiming,
        public string $imageStyle,
        public ?string $tone,
        public int $durationTargetMinutes,
    ) {
        if (NarrativeFramework::tryFrom($narrativeFramework) === null) {
            throw new InvalidArgumentException(
                "Preset '{$key}': unknown narrative framework '{$narrativeFramework}'."
            );
        }

        if (! in_array($imageStyle, ImageStyleTemplate::styles(), true)) {
            throw new InvalidArgumentException(
                "Preset '{$key}': unknown image style '{$imageStyle}'."
            );
        }

        if ($gameType !== null && ! in_array($gameType, self::GAME_TYPES, true)) {
            throw new InvalidArgumentException(
                "Preset '{$key}': unknown game type '{$gameType}'."
            );
        }

        if (! in_array($quizTiming, self::QUIZ_TIMINGS, true)) {
            throw new InvalidArgumentException(
                "Preset '{$key}': invalid quiz timing '{$quizTiming}'."
            );
        }

        if ($durationTargetMinutes <= 0) {
            throw new InvalidArgumentException(
                "Preset '{$key}': duration target must be a positive number of minutes."
            );
        }

        if ($quizQuestionCount <= 0) {
            throw new InvalidArgumentException(
                "Preset '{$key}': quiz question count must be positive."
            );
        }
    }

    /**
     * Lesson-create attributes: caller attributes merged over the preset defaults (caller wins).
     * Pure — neither input is mutated. Every key exists on Lesson::$fillable, so the result is
     * safe to hand straight to Lesson::create() alongside teacher_id/topic/etc.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function apply(array $attributes = []): array
    {
        // Mirror the wizard's persist() rules: quiz fields only travel with a quiz,
        // game_config only with a story game (see Step1Settings / Step2Story).
        $isQuiz = $this->includeGame && $this->gameType === 'quiz';
        $isStoryGame = $this->includeGame && $this->gameType === 'story_game';

        $defaults = [
            'narrative_framework' => $this->narrativeFramework,
            'include_game' => $this->includeGame,
            'game_type' => $this->includeGame ? $this->gameType : null,
            'game_config' => $isStoryGame ? $this->gameConfigDefaults : null,
            'quiz_question_count' => $isQuiz ? $this->quizQuestionCount : null,
            'quiz_timing' => $isQuiz ? $this->quizTiming : null,
            'image_style' => $this->imageStyle,
            'tone' => $this->tone,
            'duration_seconds' => $this->durationTargetMinutes * 60,
        ];

        return array_merge($defaults, $attributes);
    }

    public function framework(): NarrativeFramework
    {
        return NarrativeFramework::from($this->narrativeFramework);
    }
}
