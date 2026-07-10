<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NarrativeFramework;
use App\Lessons\LessonPreset;
use App\Lessons\LessonPresets;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Support\ImageStyleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LessonPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_contains_the_four_lesson_types(): void
    {
        $this->assertSame(
            ['story', 'game_story', 'comprehension_check', 'deep_dive'],
            array_keys(LessonPresets::all()),
        );
    }

    public function test_every_preset_carries_valid_defaults(): void
    {
        foreach (LessonPresets::all() as $key => $preset) {
            $this->assertSame($key, $preset->key);
            $this->assertNotSame('', $preset->label);
            $this->assertNotSame('', $preset->description);

            $this->assertInstanceOf(
                NarrativeFramework::class,
                NarrativeFramework::tryFrom($preset->narrativeFramework),
                "Preset '{$key}' has an invalid narrative framework.",
            );

            $this->assertContains(
                $preset->imageStyle,
                ImageStyleTemplate::styles(),
                "Preset '{$key}' has an unknown image style.",
            );

            if ($preset->gameType !== null) {
                $this->assertContains(
                    $preset->gameType,
                    LessonPreset::GAME_TYPES,
                    "Preset '{$key}' has an unknown game type.",
                );
            }

            $this->assertGreaterThan(0, $preset->durationTargetMinutes);
            $this->assertGreaterThan(0, $preset->quizQuestionCount);
            $this->assertContains($preset->quizTiming, LessonPreset::QUIZ_TIMINGS);
        }
    }

    public function test_game_story_preset_uses_the_branching_framework_with_print_pack_default(): void
    {
        $preset = LessonPresets::find('game_story');

        $this->assertSame(NarrativeFramework::Branching->value, $preset->narrativeFramework);
        $this->assertSame('story_game', $preset->gameType);
        $this->assertSame(['print_pack' => false], $preset->gameConfigDefaults);
    }

    public function test_apply_lets_caller_attributes_override_preset_defaults(): void
    {
        $preset = LessonPresets::find('story');

        $attributes = $preset->apply([
            'image_style' => 'etching',
            'tone' => 'urgent and direct',
        ]);

        $this->assertSame('etching', $attributes['image_style']);
        $this->assertSame('urgent and direct', $attributes['tone']);
        // Untouched defaults survive the merge.
        $this->assertSame(NarrativeFramework::HerosJourney->value, $attributes['narrative_framework']);
        $this->assertSame('after', $attributes['quiz_timing']);
    }

    public function test_apply_is_pure_and_does_not_mutate_caller_input(): void
    {
        $preset = LessonPresets::find('story');
        $input = ['tone' => 'calm and reflective'];

        $preset->apply($input);

        $this->assertSame(['tone' => 'calm and reflective'], $input);
    }

    public function test_apply_produces_attributes_lesson_create_accepts(): void
    {
        $teacher = User::factory()->teacher()->create();

        foreach (LessonPresets::all() as $key => $preset) {
            $lesson = Lesson::create($preset->apply([
                'teacher_id' => $teacher->id,
                'topic' => 'The Roman Republic',
                'subject' => 'history',
                'grade_level' => '8th grade',
            ]));

            $this->assertTrue($lesson->exists, "Preset '{$key}' did not create a lesson.");
            $this->assertSame($preset->framework(), $lesson->narrative_framework);
            $this->assertSame($preset->durationTargetMinutes * 60, $lesson->duration_seconds);
        }
    }

    public function test_apply_persists_quiz_defaults_for_quiz_presets_and_game_config_for_story_games(): void
    {
        $teacher = User::factory()->teacher()->create();

        $check = Lesson::create(LessonPresets::find('comprehension_check')->apply([
            'teacher_id' => $teacher->id,
            'topic' => 'Ancient Egypt',
            'subject' => 'history',
            'grade_level' => '6th grade',
        ]));

        $this->assertSame(8, $check->quiz_question_count);
        $this->assertSame('during', $check->quiz_timing);
        $this->assertNull($check->game_config);

        $game = Lesson::create(LessonPresets::find('game_story')->apply([
            'teacher_id' => $teacher->id,
            'topic' => 'The French Revolution',
            'subject' => 'history',
            'grade_level' => '8th grade',
        ]));

        $this->assertSame('story_game', $game->game_type);
        $this->assertSame(['print_pack' => false], $game->game_config);
        $this->assertNull($game->quiz_timing);
        $this->assertNull($game->quiz_question_count);
    }

    public function test_find_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull(LessonPresets::find('explore_the_map'));
    }

    public function test_construction_with_a_bogus_framework_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown narrative framework 'three_act'");

        new LessonPreset(
            key: 'bogus',
            label: 'Bogus',
            description: 'Should never construct.',
            narrativeFramework: 'three_act',
            includeGame: false,
            gameType: null,
            gameConfigDefaults: [],
            quizQuestionCount: 4,
            quizTiming: 'after',
            imageStyle: 'ink',
            tone: null,
            durationTargetMinutes: 10,
        );
    }

    public function test_construction_with_a_bogus_image_style_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown image style 'watercolour'");

        new LessonPreset(
            key: 'bogus',
            label: 'Bogus',
            description: 'Should never construct.',
            narrativeFramework: NarrativeFramework::Mystery->value,
            includeGame: false,
            gameType: null,
            gameConfigDefaults: [],
            quizQuestionCount: 4,
            quizTiming: 'after',
            imageStyle: 'watercolour',
            tone: null,
            durationTargetMinutes: 10,
        );
    }

    public function test_construction_with_a_bogus_game_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown game type 'escape_room'");

        new LessonPreset(
            key: 'bogus',
            label: 'Bogus',
            description: 'Should never construct.',
            narrativeFramework: NarrativeFramework::Mystery->value,
            includeGame: true,
            gameType: 'escape_room',
            gameConfigDefaults: [],
            quizQuestionCount: 4,
            quizTiming: 'after',
            imageStyle: 'ink',
            tone: null,
            durationTargetMinutes: 10,
        );
    }
}
