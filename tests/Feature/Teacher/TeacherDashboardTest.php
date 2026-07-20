<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_only_the_teachers_classes_lessons_and_results(): void
    {
        $teacher = User::factory()->teacher()->create(['name' => 'Sarah Teacher']);
        $otherTeacher = User::factory()->teacher()->create();

        $lesson = Lesson::factory()->for($teacher, 'teacher')->create([
            'title' => 'The Dutch Revolt',
            'topic' => 'The Eighty Years War',
            'canon_theme' => 'governance',
        ]);
        Lesson::factory()->for($otherTeacher, 'teacher')->create([
            'title' => 'A Private Lesson',
        ]);

        $classroom = Classroom::factory()->create([
            'teacher_id' => $teacher->id,
            'name' => 'Group 7A',
            'is_active' => true,
        ]);
        $member = ClassroomMember::create([
            'classroom_id' => $classroom->id,
            'display_name' => 'Emma V.',
        ]);
        QuizScore::create([
            'lesson_id' => $lesson->id,
            'classroom_member_id' => $member->id,
            'nickname' => 'Emma V.',
            'score' => 20,
            'correct' => 2,
            'total' => 2,
        ]);

        $this->actingAs($teacher)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('Group 7A')
            ->assertSee('The Dutch Revolt')
            // Lessons are grouped into place shelves now (Netherlands / France / Spain /
            // Europe), not Canon-theme shelves — 'dutch' in the title lands this one here.
            ->assertSee('Netherlands')
            ->assertSee('100%')
            ->assertDontSee('A Private Lesson');
    }

    public function test_dashboard_has_useful_empty_states(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('No active classes yet')
            ->assertSee('No lessons yet')
            ->assertSee('No quiz activity yet.');
    }
}
