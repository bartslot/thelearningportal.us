<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A dashboard lesson card opens straight on Preview (wizard step 5) rather than resuming whatever
 * step the wizard was last left on — teachers open an existing lesson to watch it.
 */
class LessonCardEntryStepTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
    }

    private function lesson(LessonStatus $status, bool $withScene = true, int $wizardStep = 2): Lesson
    {
        $lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Columbus', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => $status, 'wizard_step' => $wizardStep,
        ]);

        if ($withScene) {
            Scene::create([
                'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
                'script_segment' => 'x', 'image_path' => 'a.png', 'audio_path' => 'a.mp3',
                'status' => 'ready',
            ]);
        }

        return $lesson->fresh();
    }

    public function test_a_lesson_with_scenes_opens_on_preview(): void
    {
        foreach ([LessonStatus::Published, LessonStatus::Previewable, LessonStatus::Configuring, LessonStatus::ScenesReady] as $status) {
            $this->assertSame(5, $this->lesson($status)->cardEntryStep(), $status->value.' should open Preview');
        }
    }

    /** Mid-generation, the progress screen is the only useful view — resume instead. */
    public function test_a_generating_lesson_resumes_instead_of_previewing(): void
    {
        foreach ([LessonStatus::Generating, LessonStatus::ScenesGenerating, LessonStatus::Outlining, LessonStatus::FetchingSources] as $status) {
            $this->assertNull($this->lesson($status)->cardEntryStep(), $status->value.' must not jump to Preview');
        }
    }

    public function test_a_failed_lesson_resumes_so_the_teacher_sees_the_error(): void
    {
        $this->assertNull($this->lesson(LessonStatus::Failed)->cardEntryStep());
    }

    public function test_a_lesson_with_no_scenes_resumes_the_wizard(): void
    {
        $this->assertNull($this->lesson(LessonStatus::Draft, withScene: false)->cardEntryStep());
    }

    /** The dashboard must render the step-5 URL, and must not regress into an N+1 per card. */
    public function test_the_dashboard_links_a_ready_lesson_straight_to_preview(): void
    {
        $lesson = $this->lesson(LessonStatus::Published);

        $this->actingAs($this->teacher)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee(route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 5]), escape: false);
    }

    public function test_the_dashboard_links_a_generating_lesson_to_the_resume_url(): void
    {
        $lesson = $this->lesson(LessonStatus::ScenesGenerating);

        $this->actingAs($this->teacher)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee(route('teacher.lessons.show', $lesson), escape: false)
            ->assertDontSee(route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 5]), escape: false);
    }
}
