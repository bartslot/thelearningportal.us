<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin is a superuser role: teacher-owned records (lessons, classrooms) are
 * managed by their owner AND by any admin, whoever created them.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function lessonOwnedBy(User $teacher): Lesson
    {
        return Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'X',
            'subject' => 'history',
            'grade_level' => '9th',
        ]);
    }

    public function test_admin_can_open_another_teachers_wizard(): void
    {
        $lesson = $this->lessonOwnedBy(User::factory()->create());
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get("/teacher/lessons/{$lesson->id}/wizard?step=1")
            ->assertOk();
    }

    public function test_admin_dashboard_lists_every_teachers_lessons(): void
    {
        $lesson = $this->lessonOwnedBy(User::factory()->create());
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/teacher/lessons')
            ->assertOk()
            ->assertSee($lesson->topic);
    }

    public function test_teachers_still_only_see_their_own_lessons(): void
    {
        $lesson = $this->lessonOwnedBy(User::factory()->create(['name' => 'Owner']));
        $other = User::factory()->create();

        $this->actingAs($other)
            ->get("/teacher/lessons/{$lesson->id}/wizard?step=1")
            ->assertForbidden();
    }

    public function test_admin_gets_the_owner_preview_toolbar_on_another_teachers_lesson(): void
    {
        $lesson = $this->lessonOwnedBy(User::factory()->create());
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get("/lesson/{$lesson->lesson_code}")
            ->assertOk()
            ->assertSee('Edit scene');
    }

    public function test_admin_can_manage_another_teachers_classroom(): void
    {
        $teacher = User::factory()->create();
        $classroom = Classroom::create([
            'teacher_id' => $teacher->id,
            'name' => 'Class 6A',
            'join_code' => 'ABC123',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get("/teacher/classes/{$classroom->id}")
            ->assertOk();
    }
}
