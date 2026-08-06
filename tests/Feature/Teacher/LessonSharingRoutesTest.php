<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sharing and copying routes, over HTTP.
 *
 * PublicLessonsTest covers the rules in LessonSharing. This covers the wiring: the routes resolve
 * the lesson UNSCOPED and let the service decide, because "may I share this" and "may I copy this"
 * have different answers and a scoped route binding would apply one rule to both questions.
 */
class LessonSharingRoutesTest extends TestCase
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

    public function test_an_author_can_share_their_lesson(): void
    {
        $author = User::factory()->create(['role' => 'teacher']);
        $lesson = $this->lesson($author);

        $this->actingAs($author)
            ->patch(route('teacher.lessons.sharing', $lesson), ['is_public' => true])
            ->assertRedirect();

        $this->assertTrue($lesson->fresh()->is_public);
    }

    public function test_another_teacher_cannot_share_someone_elses_lesson(): void
    {
        $author = User::factory()->create(['role' => 'teacher']);
        $other = User::factory()->create(['role' => 'teacher']);
        $lesson = $this->lesson($author);

        $this->actingAs($other)
            ->patch(route('teacher.lessons.sharing', $lesson), ['is_public' => true])
            ->assertSessionHas('error');

        $this->assertFalse($lesson->fresh()->is_public, 'a stranger shared somebody else\'s lesson');
    }

    public function test_copying_a_shared_lesson_lands_in_the_editor(): void
    {
        $author = User::factory()->create(['role' => 'teacher']);
        $other = User::factory()->create(['role' => 'teacher']);
        $shared = $this->lesson($author, ['is_public' => true]);

        $response = $this->actingAs($other)->post(route('teacher.lessons.copy', $shared));

        $copy = Lesson::where('teacher_id', $other->id)->firstOrFail();
        $response->assertRedirect(route('teacher.lessons.wizard', ['lesson' => $copy->id, 'step' => 3]));
    }

    public function test_a_private_lesson_cannot_be_copied_over_http(): void
    {
        // The route binding is unscoped, so this is the check that actually stops a guessed id.
        $author = User::factory()->create(['role' => 'teacher']);
        $other = User::factory()->create(['role' => 'teacher']);
        $private = $this->lesson($author);

        $this->actingAs($other)
            ->post(route('teacher.lessons.copy', $private))
            ->assertSessionHas('error');

        $this->assertSame(0, Lesson::where('teacher_id', $other->id)->count());
    }

    public function test_signed_out_visitors_are_turned_away(): void
    {
        $lesson = $this->lesson(User::factory()->create(), ['is_public' => true]);

        $this->post(route('teacher.lessons.copy', $lesson))->assertRedirect(route('login'));
        $this->patch(route('teacher.lessons.sharing', $lesson), ['is_public' => true])->assertRedirect(route('login'));
    }
}
