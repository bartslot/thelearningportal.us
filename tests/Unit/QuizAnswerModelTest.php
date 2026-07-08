<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LessonStatus;
use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizAnswer;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizAnswerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_score_has_answers_and_optional_classroom_member(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'X', 'subject' => 'history',
            'grade_level' => '8', 'status' => LessonStatus::Published,
        ]);
        $classroom = Classroom::create(['teacher_id' => $teacher->id, 'name' => '7B']);
        $member = ClassroomMember::create(['classroom_id' => $classroom->id, 'display_name' => 'Emma V.']);

        $score = QuizScore::create([
            'lesson_id' => $lesson->id, 'nickname' => 'Emma V.', 'score' => 20,
            'correct' => 2, 'total' => 3,
            'classroom_member_id' => $member->id, 'source' => 'paper',
        ]);
        QuizAnswer::create([
            'quiz_score_id' => $score->id, 'question_order' => 1,
            'question_text' => 'Why did X happen?', 'chosen_text' => 'Because Y',
            'correct_text' => 'Because Y', 'was_correct' => true,
            'response_ms' => 3200, 'asks_ahead' => false,
        ]);

        $this->assertSame('paper', $score->fresh()->source);
        $this->assertCount(1, $score->answers);
        $this->assertTrue($score->answers->first()->was_correct);
        $this->assertSame('Emma V.', $score->member->display_name);
        $this->assertCount(1, $member->scores);
    }

    public function test_member_display_name_is_unique_per_classroom_normalized(): void
    {
        $teacher = User::factory()->create();
        $classroom = Classroom::create(['teacher_id' => $teacher->id, 'name' => '7B']);
        ClassroomMember::create(['classroom_id' => $classroom->id, 'display_name' => 'Emma V.']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ClassroomMember::create(['classroom_id' => $classroom->id, 'display_name' => 'emma v.']);
    }
}
