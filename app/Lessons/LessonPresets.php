<?php

declare(strict_types=1);

namespace App\Lessons;

use App\Enums\NarrativeFramework;

/**
 * The catalog of named lesson-type presets (K-13). One source of truth: the wizard picker and
 * the K-12 creation chat both read from here. Custom teacher-defined presets are explicitly v2.
 */
final class LessonPresets
{
    /**
     * @return array<string, LessonPreset> keyed by preset key
     */
    public static function all(): array
    {
        $presets = [
            new LessonPreset(
                key: 'story',
                label: 'Story lesson',
                description: 'A narrative-first lesson with a short quiz at the end to check understanding.',
                narrativeFramework: NarrativeFramework::HerosJourney->value,
                includeGame: true,
                gameType: 'quiz',
                gameConfigDefaults: [],
                quizQuestionCount: 4,
                quizTiming: 'after',
                imageStyle: 'ink',
                tone: 'dramatic',
                durationTargetMinutes: 10,
            ),
            new LessonPreset(
                key: 'game_story',
                label: 'Spel-verhaal',
                description: 'A branching story game where teams make choices that steer the plot and their meters.',
                narrativeFramework: NarrativeFramework::Branching->value,
                includeGame: true,
                gameType: 'story_game',
                gameConfigDefaults: ['print_pack' => false],
                quizQuestionCount: 4,
                quizTiming: 'after',
                imageStyle: 'ink',
                tone: 'urgent and direct',
                durationTargetMinutes: 15,
            ),
            new LessonPreset(
                key: 'comprehension_check',
                label: 'Comprehension check',
                description: 'A short lesson with minimal scenes and quiz questions woven in as you go.',
                narrativeFramework: NarrativeFramework::InMediasRes->value,
                includeGame: true,
                gameType: 'quiz',
                gameConfigDefaults: [],
                quizQuestionCount: 8,
                quizTiming: 'during',
                imageStyle: 'ink',
                tone: null,
                durationTargetMinutes: 5,
            ),
            new LessonPreset(
                key: 'deep_dive',
                label: 'Deep dive',
                description: 'A longer story lesson that follows a rise and fall in depth, closed by a quiz.',
                narrativeFramework: NarrativeFramework::RiseAndFall->value,
                includeGame: true,
                gameType: 'quiz',
                gameConfigDefaults: [],
                quizQuestionCount: 6,
                quizTiming: 'both',
                imageStyle: 'ink',
                tone: 'calm and reflective',
                durationTargetMinutes: 20,
            ),
        ];

        return array_combine(
            array_map(fn (LessonPreset $preset): string => $preset->key, $presets),
            $presets,
        );
    }

    public static function find(string $key): ?LessonPreset
    {
        return self::all()[$key] ?? null;
    }
}
