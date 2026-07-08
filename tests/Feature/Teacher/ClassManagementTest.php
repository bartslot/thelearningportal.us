<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Livewire\Teacher\ClassroomManage;
use App\Livewire\Teacher\Classrooms;
use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassManagementTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        return User::factory()->create(['role' => 'teacher']);
    }

    public function test_teacher_creates_and_deletes_a_class(): void
    {
        $teacher = $this->teacher();

        Livewire::actingAs($teacher)->test(Classrooms::class)
            ->set('newName', 'Group 7A')->set('newGrade', '7')
            ->call('create')
            ->assertHasNoErrors();

        $class = Classroom::where('teacher_id', $teacher->id)->firstOrFail();
        $this->assertSame('Group 7A', $class->name);
        $this->assertNotEmpty($class->join_code);

        Livewire::actingAs($teacher)->test(Classrooms::class)
            ->call('delete', $class->id);

        $this->assertSoftDeleted('classrooms', ['id' => $class->id]);
    }

    public function test_class_name_is_required(): void
    {
        Livewire::actingAs($this->teacher())->test(Classrooms::class)
            ->set('newName', '')->call('create')
            ->assertHasErrors(['newName' => 'required']);
    }

    public function test_a_teacher_cannot_delete_another_teachers_class(): void
    {
        $owner = $this->teacher();
        $class = Classroom::factory()->create(['teacher_id' => $owner->id]);

        try {
            Livewire::actingAs($this->teacher())->test(Classrooms::class)
                ->call('delete', $class->id);
            $this->fail('Expected a not-found for another teacher\'s class.');
        } catch (ModelNotFoundException) {
            // teacher-scoped findOrFail refused the foreign id
        }

        $this->assertDatabaseHas('classrooms', ['id' => $class->id, 'deleted_at' => null]);
    }

    public function test_manage_page_is_owner_only(): void
    {
        $class = Classroom::factory()->create(['teacher_id' => $this->teacher()->id]);

        $this->actingAs($this->teacher())
            ->get(route('teacher.classes.manage', $class))
            ->assertForbidden();
    }

    public function test_roster_add_rename_and_duplicate_guard(): void
    {
        $teacher = $this->teacher();
        $class = Classroom::factory()->create(['teacher_id' => $teacher->id]);

        $component = Livewire::actingAs($teacher)->test(ClassroomManage::class, ['classroom' => $class])
            ->set('newMember', 'Emma V.')->call('addMember')->assertHasNoErrors();
        $this->assertDatabaseHas('classroom_members', ['classroom_id' => $class->id, 'display_name' => 'Emma V.']);

        // Case-insensitive duplicate is rejected by the normalized unique rule.
        $component->set('newMember', 'emma v.')->call('addMember')->assertHasErrors('newMember');
        $this->assertSame(1, ClassroomMember::where('classroom_id', $class->id)->count());

        $member = ClassroomMember::where('classroom_id', $class->id)->first();
        $component->call('startEdit', $member->id)
            ->set('editingMemberName', 'Emma W.')->call('saveMember')->assertHasNoErrors();
        $this->assertSame('Emma W.', $member->fresh()->display_name);
    }

    public function test_removing_a_child_keeps_their_scores_anonymously(): void
    {
        $teacher = $this->teacher();
        $class = Classroom::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'X', 'subject' => 'history', 'grade_level' => '7',
        ]);
        $member = ClassroomMember::create(['classroom_id' => $class->id, 'display_name' => 'Emma V.']);
        $score = QuizScore::create([
            'lesson_id' => $lesson->id, 'nickname' => 'Emma V.', 'classroom_member_id' => $member->id,
            'score' => 10, 'correct' => 1, 'total' => 1,
        ]);

        Livewire::actingAs($teacher)->test(ClassroomManage::class, ['classroom' => $class])
            ->call('removeMember', $member->id);

        $this->assertDatabaseMissing('classroom_members', ['id' => $member->id]);
        $this->assertDatabaseHas('quiz_scores', ['id' => $score->id, 'classroom_member_id' => null]);
    }

    public function test_assign_and_unassign_lesson_scoped_to_owner(): void
    {
        $teacher = $this->teacher();
        $class = Classroom::factory()->create(['teacher_id' => $teacher->id]);
        $mine = Lesson::create(['teacher_id' => $teacher->id, 'topic' => 'Mine', 'subject' => 'history', 'grade_level' => '7']);
        $theirs = Lesson::create(['teacher_id' => $this->teacher()->id, 'topic' => 'Theirs', 'subject' => 'history', 'grade_level' => '7']);

        $component = Livewire::actingAs($teacher)->test(ClassroomManage::class, ['classroom' => $class])
            ->call('assignLesson', $mine->id);
        $this->assertTrue($class->lessons()->where('lessons.id', $mine->id)->exists());

        // Cannot attach another teacher's lesson.
        try {
            $component->call('assignLesson', $theirs->id);
            $this->fail('Expected a not-found for another teacher\'s lesson.');
        } catch (ModelNotFoundException) {
            // owner-scoped findOrFail refused it
        }
        $this->assertFalse($class->lessons()->where('lessons.id', $theirs->id)->exists());

        $component->call('unassignLesson', $mine->id);
        $this->assertFalse($class->fresh()->lessons()->where('lessons.id', $mine->id)->exists());
    }
}
