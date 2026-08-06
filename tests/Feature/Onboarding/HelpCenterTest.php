<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\OnboardingStatus;
use App\Livewire\Help;
use App\Models\User;
use App\Support\HelpGuide;
use App\Support\HelpMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string,mixed>> */
    private function topicsFor(string $audience): array
    {
        return array_values(array_filter(
            HelpGuide::topics(),
            fn (array $topic) => ($topic['audience'] ?? 'all') === $audience,
        ));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));
    }

    public function test_the_help_page_lists_every_teacher_topic_with_its_sections(): void
    {
        $response = $this->actingAs(User::factory()->teacher()->create())
            ->get(route('help.index'))
            ->assertOk();

        foreach ($this->topicsFor('all') as $topic) {
            $response->assertSee($topic['title']);
            $response->assertSee($topic['summary']);

            foreach ($topic['sections'] as $section) {
                $response->assertSee($section['heading']);
            }
        }
    }

    public function test_a_teacher_is_never_shown_the_admin_topics(): void
    {
        $response = $this->actingAs(User::factory()->teacher()->create())
            ->get(route('help.index'))
            ->assertOk();

        $adminTopics = $this->topicsFor('admin');

        $this->assertNotEmpty($adminTopics, 'There should be admin-only help.');

        foreach ($adminTopics as $topic) {
            $response->assertDontSee($topic['title']);
        }
    }

    public function test_an_admin_sees_the_teacher_help_and_the_admin_help(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('help.index'))
            ->assertOk();

        foreach (HelpGuide::topics() as $topic) {
            $response->assertSee($topic['title']);
        }
    }

    public function test_searching_narrows_the_page_to_matching_topics(): void
    {
        Livewire::actingAs(User::factory()->teacher()->create())
            ->test(Help::class)
            ->set('search', 'join code')
            ->assertSee('Classes and students')
            ->assertDontSee('The Time-Map');
    }

    public function test_search_ignores_case_and_accents(): void
    {
        $component = Livewire::actingAs(User::factory()->teacher()->create())
            ->test(Help::class)
            ->set('search', 'TIME-MAP');

        $this->assertNotEmpty($component->instance()->topics());
    }

    public function test_search_also_offers_the_place_in_the_app(): void
    {
        Livewire::actingAs(User::factory()->teacher()->create())
            ->test(Help::class)
            ->set('search', 'results')
            ->assertSee('Go straight there')
            ->assertSee(route('teacher.results.hub'));
    }

    public function test_admin_shortcuts_stay_hidden_from_teachers(): void
    {
        $teacher = Livewire::actingAs(User::factory()->teacher()->create())
            ->test(Help::class)
            ->set('search', 'avatar');

        $this->assertSame([], array_column($teacher->instance()->shortcuts(), 'route'));

        $admin = Livewire::actingAs(User::factory()->create(['role' => 'admin']))
            ->test(Help::class)
            ->set('search', 'avatar');

        $this->assertContains('admin.narrators.index', array_column($admin->instance()->shortcuts(), 'route'));
    }

    public function test_a_search_with_no_hits_says_so(): void
    {
        Livewire::actingAs(User::factory()->teacher()->create())
            ->test(Help::class)
            ->set('search', 'zzzznothinghere')
            ->assertSee('Nothing here matches');
    }

    public function test_every_illustrated_section_points_at_a_screenshot_the_capture_command_takes(): void
    {
        $keys = array_keys(HelpMedia::SHOTS);

        foreach (HelpGuide::topics() as $topic) {
            foreach ($topic['sections'] as $section) {
                if (! empty($section['image'])) {
                    $this->assertContains(
                        $section['image'],
                        $keys,
                        "Help section '{$section['heading']}' wants a screenshot nothing captures.",
                    );
                }
            }
        }
    }

    public function test_screenshots_fall_back_to_english_when_a_language_has_none(): void
    {
        // 'xx' has no captured set; English stands in so the page still illustrates itself.
        $this->assertSame(
            HelpMedia::url('dashboard', 'en'),
            HelpMedia::url('dashboard', 'xx'),
        );
    }

    public function test_replaying_the_tutorial_restarts_it_from_the_beginning(): void
    {
        $teacher = User::factory()->teacher()->create([
            'onboarding_status' => OnboardingStatus::Completed,
            'onboarding_step' => 5,
            'onboarded_at' => now()->subMonth(),
        ]);

        Livewire::actingAs($teacher)
            ->test(Help::class)
            ->call('startTutorial')
            ->assertRedirect(route('teacher.dashboard'));

        $teacher->refresh();

        $this->assertSame(OnboardingStatus::Pending, $teacher->onboarding_status);
        $this->assertSame(0, $teacher->onboarding_step);
        $this->assertNull($teacher->onboarded_at);
    }

    public function test_a_tour_closed_half_way_is_offered_as_a_resume_and_keeps_its_step(): void
    {
        $teacher = User::factory()->teacher()->create([
            'onboarding_status' => OnboardingStatus::Dismissed,
            'onboarding_step' => 3,
            'onboarded_at' => now()->subHour(),
        ]);

        Livewire::actingAs($teacher)
            ->test(Help::class)
            ->assertSee('Resume the welcome tour')
            ->call('startTutorial');

        $teacher->refresh();

        $this->assertSame(OnboardingStatus::Active, $teacher->onboarding_status);
        $this->assertSame(3, $teacher->onboarding_step);
    }

    public function test_a_finished_tour_is_offered_as_a_replay(): void
    {
        $teacher = User::factory()->teacher()->create([
            'onboarding_status' => OnboardingStatus::Completed,
            'onboarding_step' => 6,
            'onboarded_at' => now(),
        ]);

        Livewire::actingAs($teacher)->test(Help::class)->assertSee('Replay the welcome tour');
    }

    public function test_a_student_gets_the_help_page_without_the_tour_button(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        Livewire::actingAs($student)
            ->test(Help::class)
            ->assertDontSee('Replay the welcome tour')
            ->assertDontSee('Resume the welcome tour');
    }

    public function test_every_tour_step_points_at_a_real_help_topic(): void
    {
        $topicIds = array_column(HelpGuide::topics(), 'id');

        foreach (HelpGuide::tour() as $step) {
            if ($step['topic'] === null) {
                continue;
            }

            $this->assertContains($step['topic'], $topicIds, "Tour step '{$step['key']}' links to an unknown help topic.");
        }
    }
}
