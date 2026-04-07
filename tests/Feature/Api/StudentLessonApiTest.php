<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentLessonApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function studentWithLesson(): array
    {
        $teacher   = User::factory()->teacher()->create();
        $student   = User::factory()->student()->create();
        $classroom = Classroom::factory()->create(['teacher_id' => $teacher->id]);

        $classroom->students()->attach($student->id, ['joined_at' => now()]);

        $lesson = Lesson::factory()
            ->published()
            ->withQuiz(4)
            ->create(['teacher_id' => $teacher->id]);

        $classroom->lessons()->attach($lesson->id, ['assigned_at' => now()]);

        return compact('student', 'lesson', 'classroom', 'teacher');
    }

    private function actingAsStudent(User $student): static
    {
        return $this->withToken($student->createToken('test')->plainTextToken);
    }

    // ── GET /api/v1/student/lessons ───────────────────────────────────────

    public function test_student_sees_published_lessons_in_their_classroom(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->studentWithLesson();

        $this->actingAsStudent($student)
            ->getJson('/api/v1/student/lessons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lesson->id);
    }

    public function test_student_does_not_see_lessons_from_other_classrooms(): void
    {
        $student = User::factory()->student()->create();

        // Create a lesson in a classroom the student is NOT in
        $teacher   = User::factory()->teacher()->create();
        $classroom = Classroom::factory()->create(['teacher_id' => $teacher->id]);
        $lesson    = Lesson::factory()->published()->create(['teacher_id' => $teacher->id]);
        $classroom->lessons()->attach($lesson->id, ['assigned_at' => now()]);

        $this->actingAsStudent($student)
            ->getJson('/api/v1/student/lessons')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_student_does_not_see_unpublished_lessons(): void
    {
        $teacher   = User::factory()->teacher()->create();
        $student   = User::factory()->student()->create();
        $classroom = Classroom::factory()->create(['teacher_id' => $teacher->id]);

        $classroom->students()->attach($student->id, ['joined_at' => now()]);

        // Ready (not published) lesson
        $lesson = Lesson::factory()->ready()->create(['teacher_id' => $teacher->id]);
        $classroom->lessons()->attach($lesson->id, ['assigned_at' => now()]);

        $this->actingAsStudent($student)
            ->getJson('/api/v1/student/lessons')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/student/lessons')->assertUnauthorized();
    }

    public function test_teacher_cannot_access_student_endpoints(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->withToken($teacher->createToken('test')->plainTextToken)
            ->getJson('/api/v1/student/lessons')
            ->assertForbidden();
    }

    // ── GET /api/v1/student/lessons/{id} ─────────────────────────────────

    public function test_student_can_view_lesson_detail(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->studentWithLesson();

        $this->actingAsStudent($student)
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'title', 'topic', 'grade_level',
                    'historical_figure', 'duration_seconds',
                    'video_url', 'audio_url', 'portrait_url',
                    'quiz_questions' => [['id', 'order', 'question', 'options', 'points']],
                    'my_progress'    => ['watch_seconds', 'video_completed', 'score', 'max_score', 'completed'],
                ],
            ]);
    }

    public function test_lesson_detail_does_not_expose_correct_index(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->studentWithLesson();

        $response = $this->actingAsStudent($student)
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertOk();

        // correct_index must NOT be present in quiz questions
        foreach ($response->json('data.quiz_questions') as $q) {
            $this->assertArrayNotHasKey('correct_index', $q);
        }
    }

    public function test_student_cannot_view_lesson_they_have_no_access_to(): void
    {
        $student = User::factory()->student()->create();

        // Lesson in a classroom the student is NOT enrolled in
        $teacher   = User::factory()->teacher()->create();
        $classroom = Classroom::factory()->create(['teacher_id' => $teacher->id]);
        $lesson    = Lesson::factory()->published()->create(['teacher_id' => $teacher->id]);
        $classroom->lessons()->attach($lesson->id, ['assigned_at' => now()]);

        $this->actingAsStudent($student)
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertNotFound();
    }

    // ── POST /api/v1/student/lessons/{id}/progress ───────────────────────

    public function test_student_can_save_video_progress(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->studentWithLesson();

        $this->actingAsStudent($student)
            ->postJson("/api/v1/student/lessons/{$lesson->id}/progress", [
                'watch_seconds'   => 120,
                'video_completed' => false,
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['score', 'max_score', 'score_percent', 'completed', 'correct_answers']]);
    }

    public function test_student_can_submit_quiz_and_receive_score(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->studentWithLesson();

        // All correct_index values are 0 (from withQuiz factory)
        $answers = $lesson->quizQuestions->mapWithKeys(
            fn($q) => [(string) $q->id => 0]
        )->all();

        $response = $this->actingAsStudent($student)
            ->postJson("/api/v1/student/lessons/{$lesson->id}/progress", [
                'watch_seconds'   => 300,
                'video_completed' => true,
                'answers'         => $answers,
            ])
            ->assertOk();

        $this->assertEquals(40, $response->json('data.score'));   // 4 × 10 pts
        $this->assertEquals(40, $response->json('data.max_score'));
        $this->assertEquals(100, $response->json('data.score_percent'));
        $this->assertTrue($response->json('data.completed'));
    }

    public function test_quiz_returns_correct_answers_after_submission(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->studentWithLesson();

        $answers = $lesson->quizQuestions->mapWithKeys(
            fn($q) => [(string) $q->id => 0]
        )->all();

        $response = $this->actingAsStudent($student)
            ->postJson("/api/v1/student/lessons/{$lesson->id}/progress", [
                'answers' => $answers,
            ])
            ->assertOk();

        $this->assertNotNull($response->json('data.correct_answers'));

        foreach ($lesson->quizQuestions as $q) {
            $this->assertArrayHasKey((string) $q->id, $response->json('data.correct_answers'));
            $this->assertEquals(0, $response->json("data.correct_answers.{$q->id}.correct_index"));
        }
    }

    public function test_quiz_cannot_be_submitted_twice(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->studentWithLesson();

        $answers = $lesson->quizQuestions->mapWithKeys(
            fn($q) => [(string) $q->id => 0]
        )->all();

        // First submission — all correct
        $this->actingAsStudent($student)
            ->postJson("/api/v1/student/lessons/{$lesson->id}/progress", ['answers' => $answers])
            ->assertOk();

        // Second submission — all wrong (index 1)
        $wrongAnswers = $lesson->quizQuestions->mapWithKeys(
            fn($q) => [(string) $q->id => 1]
        )->all();

        $response = $this->actingAsStudent($student)
            ->postJson("/api/v1/student/lessons/{$lesson->id}/progress", ['answers' => $wrongAnswers])
            ->assertOk();

        // Score must still be 40 (first submission locked in)
        $this->assertEquals(40, $response->json('data.score'));
    }

    public function test_watch_seconds_only_increases(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->studentWithLesson();

        $this->actingAsStudent($student)
            ->postJson("/api/v1/student/lessons/{$lesson->id}/progress", ['watch_seconds' => 200]);

        // Sending a lower value should not overwrite
        $response = $this->actingAsStudent($student)
            ->postJson("/api/v1/student/lessons/{$lesson->id}/progress", ['watch_seconds' => 50])
            ->assertOk();

        $progress = $lesson->studentProgress()->first();
        $this->assertEquals(200, $progress->watch_seconds);
    }
}
