<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonPdfTest extends TestCase
{
    use RefreshDatabase;

    private function makeLesson(User $teacher): Lesson
    {
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title' => 'Napoleon: Rise and Fall',
            'topic' => 'Napoleon',
            'subject' => 'history',
            'grade_level' => '8',
            'status' => LessonStatus::Published,
            'outline' => [
                'learning_objectives' => [
                    ['id' => 'LO1', 'text' => 'Explain how Napoleon rose to power.'],
                    ['id' => 'LO2', 'text' => 'Describe why his empire collapsed.'],
                ],
            ],
        ]);

        Scene::create(['lesson_id' => $lesson->id, 'order' => 0, 'kind' => 'narration', 'script_segment' => 'Cannon smoke rolled over the field at Austerlitz.']);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'script_segment' => 'Exile came twice; the second time was final.']);

        QuizQuestion::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'question' => 'Where was Napoleon first exiled?',
            'options' => ['Elba', 'Corsica', 'Malta', 'Saint Helena'],
            'correct_index' => 0,
            'explanation' => 'The first exile was to Elba in 1814.',
        ]);

        return $lesson;
    }

    public function test_owner_gets_pdf_handout(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->makeLesson($teacher);

        $response = $this->actingAs($teacher)
            ->get("/teacher/lessons/{$lesson->id}/print/handout");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            'handout-'.$lesson->lesson_code.'.pdf',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_non_owner_is_forbidden(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->makeLesson($teacher);

        $this->actingAs(User::factory()->create())
            ->get("/teacher/lessons/{$lesson->id}/print/handout")
            ->assertForbidden();
    }

    public function test_lesson_without_scenes_or_questions_still_renders(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Rome',
            'subject' => 'history',
            'grade_level' => '7',
            'status' => LessonStatus::Published,
        ]);

        $response = $this->actingAs($teacher)
            ->get("/teacher/lessons/{$lesson->id}/print/handout");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
