<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnswerSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_prints_questions_with_bubbles_and_is_owner_only(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create(['teacher_id' => $teacher->id, 'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        QuizQuestion::create(['lesson_id' => $lesson->id, 'order' => 1, 'question' => 'Why did X happen?', 'options' => ['a', 'b', 'c', 'd'], 'correct_index' => 0]);

        $this->actingAs($teacher)->get("/teacher/lessons/{$lesson->id}/results/answer-sheet")
            ->assertOk()
            ->assertSee('Why did X happen?')
            ->assertSee($lesson->lesson_code)
            ->assertSee('Ⓐ');

        $this->actingAs(User::factory()->create())
            ->get("/teacher/lessons/{$lesson->id}/results/answer-sheet")
            ->assertForbidden();
    }
}
