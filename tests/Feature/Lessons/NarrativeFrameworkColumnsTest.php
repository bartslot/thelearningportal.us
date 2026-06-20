<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Enums\LessonStatus;
use App\Enums\NarrativeFramework;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NarrativeFrameworkColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function newLesson(array $overrides = []): Lesson
    {
        return Lesson::create(array_merge([
            'teacher_id'  => User::factory()->create()->id,
            'topic'       => 'Ottoman Empire',
            'subject'     => 'history',
            'grade_level' => 'Age 12',
            'image_style' => 'realistic',
            'source_mode' => 'internet',
            'status'      => LessonStatus::Draft,
        ], $overrides));
    }

    public function test_lesson_defaults_to_heros_journey_framework(): void
    {
        $lesson = $this->newLesson()->fresh();

        $this->assertSame(NarrativeFramework::HerosJourney, $lesson->narrative_framework);
        $this->assertNull($lesson->protagonist_qid);
        $this->assertNull($lesson->protagonist_name);
    }

    public function test_framework_and_protagonist_persist(): void
    {
        $lesson = $this->newLesson([
            'narrative_framework' => NarrativeFramework::Branching,
            'protagonist_qid'     => 'Q8409',
            'protagonist_name'    => 'Suleiman the Magnificent',
        ])->fresh();

        $this->assertSame(NarrativeFramework::Branching, $lesson->narrative_framework);
        $this->assertSame('Q8409', $lesson->protagonist_qid);
        $this->assertSame('Suleiman the Magnificent', $lesson->protagonist_name);
    }

    public function test_scene_branch_fields_default_null_and_persist(): void
    {
        $lesson = $this->newLesson();

        $linear = Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration'])->fresh();
        $this->assertNull($linear->branch_group);
        $this->assertNull($linear->branch_role);
        $this->assertNull($linear->branch_choice_label);

        $branch = Scene::create([
            'lesson_id'           => $lesson->id,
            'order'               => 2,
            'kind'                => 'narration',
            'branch_group'        => 1,
            'branch_role'         => 'option_a',
            'branch_choice_label' => 'Cross the Rubicon',
        ])->fresh();

        $this->assertSame(1, $branch->branch_group);
        $this->assertSame('option_a', $branch->branch_role);
        $this->assertSame('Cross the Rubicon', $branch->branch_choice_label);
    }
}
