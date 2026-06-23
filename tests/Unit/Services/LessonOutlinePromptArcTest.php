<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Lesson;
use App\Services\LessonOutlinePrompt;
use Tests\TestCase;

/**
 * The teacher's chosen narrative arc (Spec 1) must reach the outline LLM: its beat template,
 * the And-But-Therefore rule, the protagonist, and the game's placement beat.
 */
class LessonOutlinePromptArcTest extends TestCase
{
    public function test_user_prompt_carries_the_heros_journey_spine_and_protagonist(): void
    {
        $lesson = new Lesson([
            'topic' => 'Ottoman Empire',
            'subject' => 'history',
            'narrative_framework' => 'heros_journey',
            'protagonist_name' => 'Suleiman the Magnificent',
            'grade_level' => 'Age 12',
            'tone' => 'storytelling',
            'include_game' => true,
            'game_type' => 'quiz',
            'quiz_question_count' => 4,
            'quiz_timing' => 'after',
        ]);

        $prompt = LessonOutlinePrompt::user($lesson, 'Source about the Ottoman Empire.');

        $this->assertStringContainsString("Hero's journey", $prompt);
        $this->assertStringContainsString('Before → Call → Trials → Crisis → Return', $prompt);
        $this->assertStringContainsString('Suleiman the Magnificent', $prompt);
        $this->assertStringContainsString('therefore', $prompt);          // And-But-Therefore rule
        $this->assertStringContainsString('"Crisis" beat', $prompt);      // game placed at the arc's tension beat
    }

    public function test_each_arc_uses_its_own_beat_template(): void
    {
        $riseAndFall = LessonOutlinePrompt::user(
            new Lesson(['topic' => 'Rome', 'subject' => 'history', 'narrative_framework' => 'rise_and_fall', 'grade_level' => 'Age 12']),
            'Source.'
        );
        $this->assertStringContainsString('Rise & fall', $riseAndFall);
        $this->assertStringContainsString('Origin → Ascent → Peak → Cracks → Collapse', $riseAndFall);

        $mystery = LessonOutlinePrompt::user(
            new Lesson(['topic' => 'Stonehenge', 'subject' => 'history', 'narrative_framework' => 'mystery', 'grade_level' => 'Age 12']),
            'Source.'
        );
        $this->assertStringContainsString('Puzzle → Clues → Twist → Reveal → Meaning', $mystery);
    }

    public function test_no_protagonist_clause_when_none_chosen(): void
    {
        $prompt = LessonOutlinePrompt::user(
            new Lesson(['topic' => 'Rome', 'subject' => 'history', 'narrative_framework' => 'in_medias_res', 'grade_level' => 'Age 12']),
            'Source.'
        );

        $this->assertStringNotContainsString('central figure the student follows', $prompt);
        $this->assertStringContainsString('Cold open → Rewind → Build → Catch-up → Aftermath', $prompt);
    }
}
