<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PruneGuestDemos;
use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Support\DemoLesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Configure" on the landing page hands a visitor with no account a throwaway teacher account and
 * a private copy of the demo lesson (App\Services\GuestDemoSession). That is a real account on the
 * public internet, so most of what is asserted here is the fence around it.
 */
class GuestDemoTest extends TestCase
{
    use RefreshDatabase;

    private function publishedDemoLesson(string $title = 'The Silk Road'): Lesson
    {
        $teacher = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret-password',
            'role' => 'teacher',
        ]);

        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title' => $title,
            'topic' => $title,
            'subject' => 'history',
            'grade_level' => '7',
            'status' => LessonStatus::Published,
        ]);

        Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'ready',
            'script' => 'Once upon a road.',
            'audio_path' => 'lessons/1/scenes/1.mp3',
        ]);

        DemoLesson::forget();

        return $lesson->refresh();
    }

    public function test_a_visitor_with_no_account_gets_their_own_copy_of_the_demo_lesson(): void
    {
        $demo = $this->publishedDemoLesson();

        $response = $this->get(route('demo.configure'));

        $guest = User::where('is_guest_demo', true)->sole();
        $copy = Lesson::where('teacher_id', $guest->id)->sole();

        $response->assertRedirect(route('teacher.lessons.wizard', ['lesson' => $copy->id, 'step' => 4]));
        $this->assertAuthenticatedAs($guest);
        $this->assertTrue($guest->isTeacher());
        $this->assertSame($demo->title, $copy->title);
        $this->assertSame(1, $copy->scenes()->count());
        // A copy is never a second published lesson in the public catalogue.
        $this->assertSame(LessonStatus::Configuring, $copy->status);
        $this->assertNotSame($demo->lesson_code, $copy->lesson_code);
    }

    public function test_editing_the_copy_leaves_the_original_lesson_alone(): void
    {
        $demo = $this->publishedDemoLesson();

        $this->get(route('demo.configure'));

        $copy = Lesson::where('teacher_id', User::where('is_guest_demo', true)->sole()->id)->sole();
        $copy->update(['title' => 'Something a stranger typed']);
        $copy->scenes()->delete();

        $demo->refresh();
        $this->assertSame('The Silk Road', $demo->title);
        $this->assertSame(1, $demo->scenes()->count());
    }

    public function test_a_second_visit_reuses_the_same_sandbox(): void
    {
        $this->publishedDemoLesson();

        $this->get(route('demo.configure'));
        $this->get(route('demo.configure'));

        $this->assertSame(1, User::where('is_guest_demo', true)->count());
        $this->assertSame(2, Lesson::count(), 'the demo lesson plus exactly one copy');
    }

    public function test_a_guest_cannot_reach_the_rest_of_the_workspace(): void
    {
        $this->publishedDemoLesson();
        $this->get(route('demo.configure'));

        $this->get(route('teacher.dashboard'))->assertForbidden();
        $this->get(route('teacher.lessons.index'))->assertForbidden();
        $this->get(route('settings.index'))->assertForbidden();
        $this->get(route('help.index'))->assertForbidden();
    }

    public function test_a_guest_is_shown_no_control_that_would_bounce_them_back(): void
    {
        $this->publishedDemoLesson();
        $this->get(route('demo.configure'));

        $copy = Lesson::where('teacher_id', User::where('is_guest_demo', true)->sole()->id)->sole();
        $wizard = $this->get(route('teacher.lessons.wizard', ['lesson' => $copy->id, 'step' => 4]));

        // "Edit story" goes to step 2, which clampForGuestDemo() pins straight back to step 4 —
        // the guest would click it and appear to land nowhere. The minimal workflow shows only
        // controls that do something.
        $wizard->assertOk()->assertDontSee(__('Edit story'));

        // The way out leads to the public site, not to a workspace page that would 403.
        $wizard->assertSee(route('home'), false)
            ->assertDontSee(route('teacher.lessons.index'), false);
    }

    public function test_a_guest_can_open_the_wizard_on_their_own_copy_but_not_on_anybody_elses(): void
    {
        $demo = $this->publishedDemoLesson();
        $this->get(route('demo.configure'));

        $copy = Lesson::where('teacher_id', User::where('is_guest_demo', true)->sole()->id)->sole();

        $this->get(route('teacher.lessons.wizard', ['lesson' => $copy->id, 'step' => 4]))->assertOk();
        $this->get(route('teacher.lessons.wizard', ['lesson' => $demo->id, 'step' => 4]))->assertForbidden();
    }

    public function test_a_guest_is_kept_off_the_generation_steps(): void
    {
        $this->publishedDemoLesson();
        $this->get(route('demo.configure'));

        $copy = Lesson::where('teacher_id', User::where('is_guest_demo', true)->sole()->id)->sole();

        // Step 3 is the Generate screen: reaching it would let an anonymous visitor spend real
        // money on AI credits. Asking for it lands on the Configure canvas instead, which is the
        // only screen that draws the rulers.
        $this->get(route('teacher.lessons.wizard', ['lesson' => $copy->id, 'step' => 3]))
            ->assertOk()
            ->assertSee('id="ruler-top"', false);
    }

    public function test_a_guest_can_neither_publish_nor_spend_ai_credits(): void
    {
        Queue::fake();
        $this->publishedDemoLesson();
        $this->get(route('demo.configure'));

        $guest = User::where('is_guest_demo', true)->sole();
        $copy = Lesson::where('teacher_id', $guest->id)->sole();
        $scene = $copy->scenes()->sole();

        $component = Livewire::actingAs($guest)->test(Step3SceneConfigurator::class, ['lesson' => $copy]);

        $component->call('publish')
            ->assertSet('publishOk', false)
            ->assertSet('publishNotice', 'Create an account to publish a lesson.');

        $component->call('regenerate', $scene->id, 'audio');

        $this->assertSame(LessonStatus::Configuring, $copy->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_signed_in_teacher_is_sent_to_build_a_real_lesson_instead(): void
    {
        $this->publishedDemoLesson();

        $teacher = User::create([
            'name' => 'Real',
            'email' => 'real@example.com',
            'password' => 'secret-password',
            'role' => 'teacher',
        ]);

        $this->actingAs($teacher)
            ->get(route('demo.configure'))
            ->assertRedirect(route('teacher.lessons.create'));

        $this->assertSame(0, User::where('is_guest_demo', true)->count());
        $this->assertAuthenticatedAs($teacher);
    }

    public function test_the_demo_route_404s_when_no_lesson_is_playable(): void
    {
        DemoLesson::forget();

        $this->get(route('demo.configure'))->assertNotFound();
    }

    public function test_a_voyage_lesson_with_no_narration_still_counts_as_playable(): void
    {
        // Voyage lessons built by `lessons:make-voyage` are map tours: every scene is ready and
        // none is narrated. Demanding narration rejected a lesson that plays perfectly.
        $lesson = $this->publishedDemoLesson('De reis van Abel Tasman');
        $lesson->scenes()->update(['audio_path' => null, 'kind' => 'voyage']);

        DemoLesson::forget();
        config(['demo.lesson_title' => 'De reis van Abel Tasman']);

        $this->assertSame($lesson->id, DemoLesson::resolve()?->id);
    }

    public function test_a_lesson_still_waiting_on_the_pipeline_is_not_offered(): void
    {
        $lesson = $this->publishedDemoLesson();
        $lesson->scenes()->update(['status' => 'pending']);

        DemoLesson::forget();

        $this->assertNull(DemoLesson::resolve());
    }

    public function test_the_configured_demo_lesson_survives_being_opened_in_the_editor(): void
    {
        // Opening a lesson on the Configure step sets status to `configuring`. The demo lesson
        // was hand-picked in config, so that must not make it vanish from the landing page.
        $lesson = $this->publishedDemoLesson('The Demo Lesson');
        $lesson->update(['status' => LessonStatus::Configuring]);

        config(['demo.lesson_title' => 'The Demo Lesson']);
        DemoLesson::forget();

        $this->assertSame($lesson->id, DemoLesson::resolve()?->id);
    }

    public function test_the_demo_lesson_falls_back_to_the_newest_playable_lesson(): void
    {
        $this->publishedDemoLesson('A Lesson With Another Name');

        config(['demo.lesson_title' => 'The Silk Road']);
        DemoLesson::forget();

        $this->assertSame('A Lesson With Another Name', DemoLesson::resolve()?->title);
    }

    public function test_pruning_removes_stale_guests_and_their_copies_only(): void
    {
        $this->publishedDemoLesson();
        $this->get(route('demo.configure'));

        $guest = User::where('is_guest_demo', true)->sole();
        $guest->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('demo:prune-guests')->assertSuccessful();

        $this->assertSame(0, User::where('is_guest_demo', true)->count());
        $this->assertSame(1, Lesson::withTrashed()->count(), 'only the original demo lesson survives');
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com']);
    }

    public function test_pruning_leaves_a_guest_who_is_still_looking_around(): void
    {
        $this->publishedDemoLesson();
        $this->get(route('demo.configure'));

        $this->artisan(PruneGuestDemos::class)->assertSuccessful();

        $this->assertSame(1, User::where('is_guest_demo', true)->count());
    }
}
