<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every method that spends money must refuse a demo guest.
 *
 * /try hands anyone on the internet a real (fenced) teacher account, and BlocksGuestDemoSpending
 * exists to stop them charging our LLM balance. It was applied to five call sites and missed the
 * script and quiz generators — all public Livewire methods, all reachable from the browser by
 * anyone holding a guest session, all callable in a loop.
 *
 * The guard is cheap and the risk is unbounded, so this asserts the fence as a whole rather than
 * one method at a time: no outbound HTTP may leave the process on a guest's behalf.
 */
class GuestSpendingIsFencedTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Lesson, 2: Scene} */
    private function guestWithLesson(): array
    {
        $guest = User::factory()->create(['role' => 'teacher', 'is_guest_demo' => true]);
        $lesson = Lesson::create([
            'teacher_id' => $guest->id,
            'topic' => 'The Dutch Revolt',
            'subject' => 'history',
            'grade_level' => '6',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'script_segment' => 'Alva marched north in 1567.',
            'status' => 'ready',
        ]);

        return [$guest, $lesson, $scene];
    }

    public function test_a_guest_cannot_spend_on_script_or_quiz_generation(): void
    {
        Http::preventStrayRequests();          // any real outbound call now throws
        Http::fake();

        [$guest, $lesson, $scene] = $this->guestWithLesson();

        $c = Livewire::actingAs($guest)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);

        // Each of these reaches an LLM in the ordinary teacher flow.
        $c->call('summarizeScriptToList', $scene->id);
        $c->call('regenerateScriptParagraph', $scene->id, 'Alva marched north.', 'shorter');
        $c->call('regenerateAllQuizQuestions');
        $c->call('generateQuizQuestion', 0);

        Http::assertNothingSent();
    }

    public function test_the_guest_is_told_why_rather_than_left_wondering(): void
    {
        Http::fake();
        [$guest, $lesson, $scene] = $this->guestWithLesson();

        Livewire::actingAs($guest)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('summarizeScriptToList', $scene->id)
            ->assertDispatched('toast');
    }
}
