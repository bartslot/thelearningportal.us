<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An error page has to offer a way out.
 *
 * The app shipped Laravel's bare defaults: a status code, a sentence, and nothing to click. A
 * teacher who hit one had the browser's back arrow and no other option — and the one that actually
 * happened was a demo visitor pressing the Help icon in the editor and being told "this demo can
 * only open the lesson it was given", on a page with no link back to the lesson they were editing.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_help_centre_is_no_longer_forbidden_to_a_demo_guest(): void
    {
        // The Help icon sits in the editor's own chrome. Refusing the one control that offers to
        // explain the product is the least useful moment available to refuse anything.
        $guest = User::factory()->create(['is_guest_demo' => true, 'role' => 'teacher']);

        $this->actingAs($guest)->get(route('help.index'))->assertOk();
    }

    public function test_a_demo_guest_is_still_fenced_out_of_everything_else(): void
    {
        $guest = User::factory()->create(['is_guest_demo' => true, 'role' => 'teacher']);

        $this->actingAs($guest)->get(route('settings.index'))->assertForbidden();
    }

    public function test_a_403_offers_a_way_back_and_says_why(): void
    {
        $guest = User::factory()->create(['is_guest_demo' => true, 'role' => 'teacher']);

        $response = $this->actingAs($guest)->get(route('settings.index'));

        $response->assertForbidden();
        // The abort() message, not a generic line: it tells the visitor exactly where they are.
        $response->assertSee('This demo can only open the lesson it was given', escape: false);
        // A real control, not just prose.
        $response->assertSee('<a href', escape: false);
        $response->assertSee('data-error-countdown', escape: false);
    }

    public function test_a_404_offers_a_way_back(): void
    {
        $response = $this->get('/a-page-that-was-never-here');

        $response->assertNotFound();
        $response->assertSee('That page does not exist');
        $response->assertSee('data-error-countdown', escape: false);
    }

    public function test_the_way_back_never_points_at_the_page_that_just_failed(): void
    {
        // Sending someone back to the URL that just 404'd is a loop, and a redirect loop with a
        // ten second timer is worse than no redirect at all.
        $response = $this->get('/nope', ['referer' => url('/nope')]);

        $response->assertNotFound();
        $response->assertDontSee('href="'.url('/nope').'"', escape: false);
    }

    public function test_an_off_site_referer_is_not_used_as_the_way_back(): void
    {
        // Otherwise a crafted link that 404s could bounce a signed-in teacher to another host, and
        // the ten second timer would do it without them touching anything.
        $response = $this->get('/nope', ['referer' => 'https://example.com/somewhere']);

        $response->assertNotFound();
        $response->assertDontSee('example.com', escape: false);
    }

    public function test_a_signed_in_teacher_is_offered_their_own_lessons(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)->get('/nope')
            ->assertNotFound()
            ->assertSee(route('teacher.dashboard'), escape: false);
    }

    public function test_a_signed_out_visitor_is_offered_the_home_page(): void
    {
        $this->get('/nope')->assertNotFound()->assertSee('Back to the home page');
    }

    public function test_the_lesson_player_still_404s_for_an_unknown_code(): void
    {
        // Guard against the error view swallowing a real 404 into a 200.
        Lesson::query()->delete();

        $this->get('/lesson/ZZZZZZ')->assertNotFound();
    }
}
