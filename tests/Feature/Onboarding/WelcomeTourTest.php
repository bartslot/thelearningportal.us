<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\OnboardingStatus;
use App\Livewire\Onboarding\WelcomeTour;
use App\Models\User;
use App\Support\HelpGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WelcomeTourTest extends TestCase
{
    use RefreshDatabase;

    private function newTeacher(array $attributes = []): User
    {
        return User::factory()->teacher()->create($attributes + [
            'onboarding_status' => OnboardingStatus::Pending,
            'onboarding_step' => 0,
            'onboarded_at' => null,
        ]);
    }

    public function test_a_brand_new_teacher_sees_the_tour_on_the_dashboard(): void
    {
        $this->actingAs($this->newTeacher())
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('data-welcome-tour', escape: false);
    }

    public function test_a_teacher_who_finished_the_tour_never_sees_it_again(): void
    {
        $teacher = $this->newTeacher([
            'onboarding_status' => OnboardingStatus::Completed,
            'onboarded_at' => now()->subDay(),
        ]);

        $this->actingAs($teacher)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertDontSee('data-welcome-tour', escape: false);
    }

    public function test_a_teacher_who_closed_the_tour_early_is_not_interrupted_again(): void
    {
        $teacher = $this->newTeacher([
            'onboarding_status' => OnboardingStatus::Dismissed,
            'onboarding_step' => 3,
            'onboarded_at' => now()->subHour(),
        ]);

        $this->actingAs($teacher)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertDontSee('data-welcome-tour', escape: false);
    }

    public function test_progress_is_recorded_as_the_teacher_moves_through_the_tour(): void
    {
        $teacher = $this->newTeacher();

        Livewire::actingAs($teacher)
            ->test(WelcomeTour::class)
            ->assertSet('visible', true)
            ->assertSet('startStep', 0)
            ->call('recordStep', 2);

        $teacher->refresh();

        $this->assertSame(OnboardingStatus::Active, $teacher->onboarding_status);
        $this->assertSame(2, $teacher->onboarding_step);
        $this->assertNull($teacher->onboarded_at, 'A half-finished tour is not an onboarded account.');
    }

    public function test_an_unfinished_tour_resumes_on_the_step_it_reached(): void
    {
        $teacher = $this->newTeacher([
            'onboarding_status' => OnboardingStatus::Active,
            'onboarding_step' => 4,
        ]);

        Livewire::actingAs($teacher)
            ->test(WelcomeTour::class)
            ->assertSet('visible', true)
            ->assertSet('startStep', 4);
    }

    public function test_a_step_index_from_the_browser_is_clamped_to_the_real_tour(): void
    {
        $teacher = $this->newTeacher();
        $lastStep = count(HelpGuide::tour()) - 1;

        Livewire::actingAs($teacher)
            ->test(WelcomeTour::class)
            ->call('recordStep', 9999)
            ->call('recordStep', -5);

        $this->assertSame(0, $teacher->fresh()->onboarding_step);

        Livewire::actingAs($teacher)->test(WelcomeTour::class)->call('recordStep', $lastStep + 40);

        $this->assertSame($lastStep, $teacher->fresh()->onboarding_step);
    }

    public function test_finishing_the_tour_completes_it(): void
    {
        $teacher = $this->newTeacher();

        Livewire::actingAs($teacher)
            ->test(WelcomeTour::class)
            ->call('finish', 6)
            ->assertSet('visible', false)
            ->assertDispatched('toast');

        $teacher->refresh();

        $this->assertSame(OnboardingStatus::Completed, $teacher->onboarding_status);
        $this->assertNotNull($teacher->onboarded_at);
    }

    public function test_closing_the_tour_early_marks_it_dismissed_and_keeps_the_step(): void
    {
        $teacher = $this->newTeacher();

        Livewire::actingAs($teacher)
            ->test(WelcomeTour::class)
            ->call('dismiss', 3)
            ->assertSet('visible', false);

        $teacher->refresh();

        $this->assertSame(OnboardingStatus::Dismissed, $teacher->onboarding_status);
        $this->assertSame(3, $teacher->onboarding_step);
        $this->assertNotNull($teacher->onboarded_at);
    }

    public function test_closing_twice_does_not_downgrade_a_completed_tour(): void
    {
        $teacher = $this->newTeacher();

        $component = Livewire::actingAs($teacher)->test(WelcomeTour::class)->call('finish', 6);
        $finishedAt = $teacher->fresh()->onboarded_at;

        $component->call('dismiss', 1);

        $teacher->refresh();

        $this->assertSame(OnboardingStatus::Completed, $teacher->onboarding_status);
        $this->assertEquals($finishedAt, $teacher->onboarded_at);
    }

    public function test_recording_progress_after_the_tour_ended_is_ignored(): void
    {
        $teacher = $this->newTeacher([
            'onboarding_status' => OnboardingStatus::Completed,
            'onboarding_step' => 6,
            'onboarded_at' => now(),
        ]);

        Livewire::actingAs($teacher)->test(WelcomeTour::class)->call('recordStep', 1);

        $teacher->refresh();

        $this->assertSame(OnboardingStatus::Completed, $teacher->onboarding_status);
        $this->assertSame(6, $teacher->onboarding_step);
    }

    public function test_the_final_call_to_action_completes_the_tour_and_sends_the_teacher_on(): void
    {
        $teacher = $this->newTeacher();

        Livewire::actingAs($teacher)
            ->test(WelcomeTour::class)
            ->call('finishAnd', 'done')
            ->assertRedirect(route('teacher.lessons.chat'));

        $this->assertSame(OnboardingStatus::Completed, $teacher->fresh()->onboarding_status);
    }

    public function test_reading_more_closes_the_tour_and_opens_that_help_section(): void
    {
        $teacher = $this->newTeacher();

        Livewire::actingAs($teacher)
            ->test(WelcomeTour::class)
            ->call('openHelp', 'results')
            ->assertRedirect(route('help.index').'#results');

        $this->assertSame(OnboardingStatus::Dismissed, $teacher->fresh()->onboarding_status);
    }

    public function test_an_unknown_help_topic_still_lands_on_the_help_page(): void
    {
        Livewire::actingAs($this->newTeacher())
            ->test(WelcomeTour::class)
            ->call('openHelp', 'nonsense')
            ->assertRedirect(route('help.index'));
    }

    public function test_every_step_carries_what_the_spotlight_engine_needs(): void
    {
        $topicIds = array_column(HelpGuide::topics(), 'id');
        $anchors = ['new-lesson', 'nav-lessons', 'nav-results', 'help'];
        $placements = ['top', 'bottom', 'left', 'right', 'center'];

        foreach (HelpGuide::tour() as $step) {
            $this->assertNotSame('', $step['title']);
            $this->assertContains($step['placement'], $placements, "Step '{$step['key']}' has an unknown placement.");

            if ($step['anchor'] !== null) {
                $this->assertContains($step['anchor'], $anchors, "Step '{$step['key']}' points at an anchor nothing renders.");
            }

            if ($step['topic'] !== null) {
                $this->assertContains($step['topic'], $topicIds, "Step '{$step['key']}' links to an unknown help topic.");
            }
        }
    }

    public function test_the_dashboard_renders_the_anchors_the_tour_points_at(): void
    {
        $response = $this->actingAs($this->newTeacher())->get(route('teacher.dashboard'))->assertOk();

        foreach (HelpGuide::tour() as $step) {
            if ($step['anchor'] !== null) {
                $response->assertSee('data-tour="'.$step['anchor'].'"', escape: false);
            }
        }
    }
}
