<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\LessonSharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The shared shelf: lessons a teacher can make public, and copy into their own library.
 *
 * A lesson used to belong to exactly one teacher and be invisible to everyone else, so a new
 * teacher signed in to an empty library. Public lessons fix that WITHOUT shared editing: the author
 * keeps the original, and anybody who wants it different takes their own copy.
 *
 * The read/write split is the load-bearing part. ownedByCurrentUser() guards every edit, delete and
 * publish; visibleToCurrentUser() is the wider one used for lists. Widening the first would have
 * put an edit button on everybody's work.
 */
class PublicLessonsTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(User $teacher, array $attributes = []): Lesson
    {
        $lesson = Lesson::create($attributes + [
            'teacher_id' => $teacher->id,
            'title' => 'A Lesson',
            'topic' => 'A Lesson',
            'subject' => 'history',
            'grade_level' => '7',
            'status' => 'published',
        ]);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'ready']);

        return $lesson;
    }

    public function test_a_teacher_sees_a_lesson_another_teacher_shared(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $shared = $this->lesson($author, ['title' => 'Shared Lesson', 'is_public' => true]);

        Auth::login($other);

        $this->assertTrue(
            Lesson::visibleToCurrentUser()->whereKey($shared->id)->exists(),
            'a shared lesson was invisible to another teacher',
        );
    }

    public function test_a_private_lesson_stays_private(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $private = $this->lesson($author, ['title' => 'Private Draft']);

        Auth::login($other);

        $this->assertFalse(Lesson::visibleToCurrentUser()->whereKey($private->id)->exists());
    }

    public function test_seeing_a_shared_lesson_does_not_mean_being_able_to_edit_it(): void
    {
        // The whole safety of the feature. ownedByCurrentUser() guards every write.
        $author = User::factory()->create();
        $other = User::factory()->create();
        $shared = $this->lesson($author, ['is_public' => true]);

        Auth::login($other);

        $this->assertTrue(Lesson::visibleToCurrentUser()->whereKey($shared->id)->exists());
        $this->assertFalse(
            Lesson::ownedByCurrentUser()->whereKey($shared->id)->exists(),
            'a shared lesson turned up in the WRITE scope',
        );
    }

    public function test_a_guest_sandbox_lesson_never_reaches_a_real_library(): void
    {
        // /try hands anonymous visitors a throwaway account and a copy of the demo. Those copies
        // must not fill every teacher's shelf.
        $guest = User::factory()->create(['is_guest_demo' => true]);
        $teacher = User::factory()->create();
        $this->lesson($guest, ['title' => 'Guest Experiment', 'is_public' => true]);

        Auth::login($teacher);

        $this->assertSame(0, Lesson::visibleToCurrentUser()->count());
    }

    public function test_classrooms_are_not_shared_by_the_same_trait(): void
    {
        // Classroom uses BelongsToTeacher too and carries pupils' names and results.
        $author = User::factory()->create();
        $other = User::factory()->create();
        Classroom::create(['teacher_id' => $author->id, 'name' => 'Groep 7', 'join_code' => 'ABC123']);

        Auth::login($other);

        $this->assertSame(0, Classroom::visibleToCurrentUser()->count());
    }

    public function test_an_admin_sees_everything(): void
    {
        $author = User::factory()->create();
        $this->lesson($author, ['title' => 'Private Draft']);
        Auth::login(User::factory()->create(['role' => 'admin']));

        $this->assertSame(1, Lesson::visibleToCurrentUser()->count());
    }

    // ── Copying ─────────────────────────────────────────────────────────────

    public function test_copying_a_shared_lesson_puts_it_in_your_own_library(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $shared = $this->lesson($author, ['title' => 'The Silk Road', 'is_public' => true]);

        $copy = app(LessonSharing::class)->copyToLibrary($shared, $other);

        $this->assertSame($other->id, $copy->teacher_id);
        $this->assertSame('The Silk Road (copy)', $copy->title);
        $this->assertNotSame($shared->lesson_code, $copy->lesson_code, 'the copy reused the original code');
        $this->assertSame(1, $copy->scenes()->count(), 'the scenes did not come with it');
    }

    public function test_a_copy_starts_private_and_unpublished(): void
    {
        // Inheriting is_public would re-share someone's edit under their name without either of
        // them choosing it; inheriting published would put an unreviewed copy in front of a class.
        $author = User::factory()->create();
        $other = User::factory()->create();
        $shared = $this->lesson($author, ['is_public' => true, 'status' => 'published']);

        $copy = app(LessonSharing::class)->copyToLibrary($shared, $other);

        $this->assertFalse($copy->is_public);
        $this->assertNotSame('published', $copy->status->value);
    }

    public function test_editing_a_copy_leaves_the_original_alone(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $shared = $this->lesson($author, ['title' => 'The Silk Road', 'is_public' => true]);

        $copy = app(LessonSharing::class)->copyToLibrary($shared, $other);
        $copy->scenes()->first()->update(['script_segment' => 'Rewritten for my class']);
        $copy->update(['title' => 'Silk Road, groep 7']);

        $this->assertSame('The Silk Road', $shared->fresh()->title);
        $this->assertNull($shared->scenes()->first()->script_segment);
    }

    public function test_a_private_lesson_cannot_be_copied_by_a_stranger(): void
    {
        // What stops a guessed id from lifting somebody's unpublished draft.
        $author = User::factory()->create();
        $other = User::factory()->create();
        $private = $this->lesson($author);

        $this->expectException(\RuntimeException::class);
        app(LessonSharing::class)->copyToLibrary($private, $other);
    }

    public function test_a_second_copy_is_numbered(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $shared = $this->lesson($author, ['title' => 'The Silk Road', 'is_public' => true]);

        $sharing = app(LessonSharing::class);
        $sharing->copyToLibrary($shared, $other);
        $second = $sharing->copyToLibrary($shared, $other);

        $this->assertSame('The Silk Road (copy 2)', $second->title);
    }

    // ── Sharing ─────────────────────────────────────────────────────────────

    public function test_only_the_author_can_share_a_lesson(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $lesson = $this->lesson($author);

        $this->expectException(\RuntimeException::class);
        app(LessonSharing::class)->setPublic($lesson, $other, true);
    }

    public function test_the_author_can_share_and_unshare(): void
    {
        $author = User::factory()->create();
        $lesson = $this->lesson($author);
        $sharing = app(LessonSharing::class);

        $sharing->setPublic($lesson, $author, true);
        $this->assertTrue($lesson->fresh()->is_public);

        $sharing->setPublic($lesson, $author, false);
        $this->assertFalse($lesson->fresh()->is_public);
    }

    public function test_a_guest_account_cannot_share_anything(): void
    {
        $guest = User::factory()->create(['is_guest_demo' => true]);
        $lesson = $this->lesson($guest);

        $this->expectException(\RuntimeException::class);
        app(LessonSharing::class)->setPublic($lesson, $guest, true);
    }
}
