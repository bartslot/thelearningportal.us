<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultsHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_lists_own_lessons_activity_grouped_per_day_and_hides_other_teachers(): void
    {
        $teacher = User::factory()->create();
        $other = User::factory()->create();
        $mine = Lesson::create(['teacher_id' => $teacher->id, 'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        $theirs = Lesson::create(['teacher_id' => $other->id, 'topic' => 'Rome', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        QuizScore::create(['lesson_id' => $mine->id, 'nickname' => 'Emma V.', 'score' => 20, 'correct' => 2, 'total' => 2]);
        QuizScore::create(['lesson_id' => $theirs->id, 'nickname' => 'Ghost', 'score' => 20, 'correct' => 2, 'total' => 2]);

        $this->actingAs($teacher)->get('/teacher/results')
            ->assertOk()
            ->assertSee('Napoleon')
            ->assertDontSee('Rome');
    }
}
