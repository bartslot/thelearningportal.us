<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\LessonScriptPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonScriptPromptTest extends TestCase
{
    use RefreshDatabase;

    private function makeLesson(array $attributes = [], string $teacherLocale = 'en'): Lesson
    {
        $teacher = User::factory()->create(['locale' => $teacherLocale]);

        return Lesson::create(array_merge([
            'teacher_id' => $teacher->id,
            'topic' => 'Julius Caesar',
            'subject' => 'history',
            'grade_level' => '8',
            'tone' => 'dramatic',
            'outline' => [
                'learning_objectives' => [
                    ['id' => 'LO1', 'text' => 'Explain why the senators feared Caesar.', 'bloom' => 'understand'],
                ],
            ],
        ], $attributes));
    }

    public function test_system_prompt_carries_the_story_hard_rules_and_exemplar(): void
    {
        $system = LessonScriptPrompt::system($this->makeLesson());

        $this->assertStringContainsString('NAMED historical person', $system);
        $this->assertStringContainsString('"but" or "therefore"', $system);
        $this->assertStringContainsString('NO bracket tags', $system);
        $this->assertStringContainsString('BAD (never write this', $system);
        $this->assertStringContainsString('GOOD (write this', $system);
        $this->assertStringContainsString('Write all narration in English', $system);
    }

    public function test_system_prompt_switches_content_language_with_teacher_locale(): void
    {
        $system = LessonScriptPrompt::system($this->makeLesson(teacherLocale: 'nl'));

        $this->assertStringContainsString('Write all narration in Dutch', $system);
    }

    public function test_user_prompt_lists_objectives_scenes_and_briefs_by_position(): void
    {
        $lesson = $this->makeLesson();
        $scenes = collect([
            new Scene(['order' => 2, 'kind' => 'narration', 'year' => '44 BC', 'location' => 'Rome']),
            new Scene(['order' => 3, 'kind' => 'game']),
        ]);
        $briefs = [
            ['beat' => 'Caesar enters the Senate', 'conflict' => 'The conspirators wait', 'characters' => ['Caesar', 'Brutus'], 'objective_ids' => ['LO1']],
            ['beat' => 'Checkpoint quiz'],
        ];

        $user = LessonScriptPrompt::user($lesson, $scenes, $briefs, 'Caesar was assassinated in 44 BC.');

        $this->assertStringContainsString('LO1: Explain why the senators feared Caesar.', $user);
        $this->assertStringContainsString('Scene order=2', $user);
        $this->assertStringContainsString('conflict: The conspirators wait', $user);
        $this->assertStringContainsString('characters: Caesar, Brutus', $user);
        $this->assertStringContainsString('Scene order=3 (GAME — 40-80 word setup only)', $user);
        $this->assertStringContainsString('Caesar was assassinated in 44 BC.', $user);
        $this->assertStringNotContainsString('previous draft was rejected', $user);
    }

    public function test_user_prompt_appends_critique_issues_on_revision(): void
    {
        $lesson = $this->makeLesson();
        $scenes = collect([new Scene(['order' => 2, 'kind' => 'narration'])]);

        $user = LessonScriptPrompt::user($lesson, $scenes, [[]], 'source', ['Scene 2: nobody acts']);

        $this->assertStringContainsString('previous draft was rejected', $user);
        $this->assertStringContainsString('Scene 2: nobody acts', $user);
    }
}
