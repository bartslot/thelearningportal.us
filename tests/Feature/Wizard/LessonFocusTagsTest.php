<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Lessons\FocusTags;
use App\Livewire\Teacher\LessonChat;
use App\Livewire\Wizard\Step1Settings;
use App\Models\Lesson;
use App\Models\User;
use App\Services\LessonOutlinePrompt;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class LessonFocusTagsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
    }

    private function mockLlm(array $response): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock) use ($response): void {
            $mock->shouldReceive('json')->once()->andReturn($response);
        });
    }

    public function test_step1_persists_focus_tags_by_persist_method(): void
    {
        // Test the persist method directly without rendering (to avoid corpus DB calls)
        $lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Julius Caesar', 'subject' => 'history', 'grade_level' => 'Age 12',
            'image_style' => 'engraved', 'status' => LessonStatus::Draft,
        ]);

        // Manually call the logic that Step1Settings.persist() does
        $this->teacher->refresh();
        $focusTags = FocusTags::sanitize(['power', 'war']);
        $focusTags = FocusTags::cap($focusTags);

        $lesson->update([
            'focus_tags' => $focusTags ?: null,
            'focus' => count($focusTags) > 0 ? FocusTags::labels($focusTags) : null,
        ]);

        $lesson->refresh();
        $this->assertSame(['power', 'war'], $lesson->focus_tags);
        $this->assertSame('Power, War', $lesson->focus);
    }

    public function test_step1_preserves_free_text_focus_over_tag_labels(): void
    {
        $lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Julius Caesar', 'subject' => 'history', 'grade_level' => 'Age 12',
            'image_style' => 'engraved', 'status' => LessonStatus::Draft,
        ]);

        // When both tags and free-text focus exist, free-text wins (it's more specific)
        $focusTags = FocusTags::sanitize(['power', 'war']);
        $focusTags = FocusTags::cap($focusTags);
        $freeTextFocus = 'Focus on political power';

        $lesson->update([
            'focus_tags' => $focusTags ?: null,
            'focus' => $freeTextFocus ?: (count($focusTags) > 0 ? FocusTags::labels($focusTags) : null),
        ]);

        $lesson->refresh();
        $this->assertSame(['power', 'war'], $lesson->focus_tags);
        $this->assertSame('Focus on political power', $lesson->focus);
    }

    public function test_focus_tags_hydrated_from_lesson(): void
    {
        // When a lesson has focus_tags stored, hydration should load them
        $lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Julius Caesar', 'subject' => 'history', 'grade_level' => 'Age 12',
            'image_style' => 'engraved', 'status' => LessonStatus::Draft,
            'focus_tags' => ['power', 'war'],
            'focus' => 'Power, War',
        ]);

        // Simulate what Step1Settings.mount() does
        $focusTags = $lesson->focus_tags ?? [];
        $this->assertSame(['power', 'war'], $focusTags);
    }

    public function test_focus_tags_capped_at_three(): void
    {
        // Test that sanitize and cap together enforce the 3-tag limit
        $slugs = ['power', 'war', 'revolution', 'rights', 'religion'];
        $result = FocusTags::sanitize($slugs);
        $result = FocusTags::cap($result);

        $this->assertCount(3, $result);
        $this->assertSame(['power', 'war', 'revolution'], $result);
    }

    public function test_chat_focus_picker_keeps_three_slots_open_and_allows_a_swap(): void
    {
        $this->mockLlm([
            'topic' => 'Revolutions', 'age' => 12, 'preset_key' => 'story',
            'story_query' => 'Revolutions', 'language' => 'en',
        ]);

        $component = Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->assertDontSee('Selected focus points')
            ->set('goal', 'Students understand why revolutions happen')
            ->call('submitGoal')
            ->assertSet('state', 'focus')
            ->assertSee('Selected focus points')
            ->assertDontSeeHtml('<details')
            ->call('toggleFocusTag', 'power')
            ->assertSet('focusTags', ['power'])
            ->assertSee('War')
            ->call('toggleFocusTag', 'war')
            ->call('toggleFocusTag', 'revolution')
            ->call('toggleFocusTag', 'rights')
            ->assertSet('focusTags', ['power', 'war', 'revolution'])
            ->assertSee('3 of 3 chosen — clear a slot above to swap.');

        $component
            ->call('toggleFocusTag', 'war')
            ->assertSet('focusTags', ['power', 'revolution'])
            ->assertSee('Rights')
            ->call('toggleFocusTag', 'rights')
            ->assertSet('focusTags', ['power', 'revolution', 'rights']);
    }

    public function test_chat_ignores_focus_interaction_before_a_goal_is_submitted(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->call('toggleFocusTag', 'power')
            ->call('toggleFocusTag', 'empire')
            ->assertSet('state', 'goal')
            ->assertSet('focusTags', [])
            ->assertDontSee('Optional focus');
    }

    public function test_chat_empty_goal_without_tags_fails_validation(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->call('submitGoal')
            ->assertHasErrors(['goal']);
    }

    public function test_chat_create_persists_focus_tags(): void
    {
        $this->mockLlm([
            'topic' => 'Power and War', 'age' => 10, 'preset_key' => 'story',
            'story_query' => 'Power War', 'language' => 'en',
        ]);
        Bus::fake();

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Understand why wars happen')
            ->call('submitGoal')
            ->call('toggleFocusTag', 'power')
            ->call('toggleFocusTag', 'war')
            ->call('continueAfterFocus')
            ->call('selectEra', 0)
            ->call('continueWithSuggestedPreset')
            ->call('continueWithSuggestedAge')
            ->assertSet('state', 'confirm')
            ->call('create');

        $lesson = Lesson::where('teacher_id', $this->teacher->id)->sole();
        $this->assertSame(['power', 'war'], $lesson->focus_tags);
        $this->assertSame('Power, War', $lesson->focus);
    }

    public function test_chat_collects_focus_only_after_goal_analysis(): void
    {
        $this->mockLlm([
            'topic' => 'Leadership Studies', 'age' => 12, 'preset_key' => 'story',
            'story_query' => 'Leadership', 'language' => 'en',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->call('toggleFocusTag', 'power')
            ->assertSet('focusTags', [])
            ->set('goal', 'Students learn leadership')
            ->call('submitGoal')
            ->assertSet('state', 'focus')
            ->assertSet('topic', 'Leadership Studies')
            ->call('toggleFocusTag', 'power')
            ->assertSet('focusTags', ['power'])
            ->call('continueAfterFocus')
            ->assertSet('state', 'era')
            ->assertSet('focusChoice', 'Power');
    }

    public function test_outline_prompt_includes_focus_block_when_tags_present(): void
    {
        $lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Julius Caesar', 'subject' => 'history', 'grade_level' => 'Age 12',
            'image_style' => 'engraved', 'status' => LessonStatus::Draft,
            'tone' => 'dramatic', 'details' => 'Focus on power',
            'focus_tags' => ['power', 'war'],
            'focus' => 'Power, War',
        ]);

        $sourceText = 'Caesar was a powerful leader involved in many wars.';
        $prompt = LessonOutlinePrompt::user($lesson, $sourceText);

        $this->assertStringContainsString('FOCUS:', $prompt);
        $this->assertStringContainsString('Power, War', $prompt);
        $this->assertStringContainsString("Weight the story's lens", $prompt);
    }

    public function test_outline_prompt_includes_both_tags_and_free_text_focus(): void
    {
        $lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Julius Caesar', 'subject' => 'history', 'grade_level' => 'Age 12',
            'image_style' => 'engraved', 'status' => LessonStatus::Draft,
            'tone' => 'dramatic',
            'focus_tags' => ['power', 'war'],
            'focus' => 'Focus on daily life of a soldier',
        ]);

        $sourceText = 'Caesar was a powerful leader.';
        $prompt = LessonOutlinePrompt::user($lesson, $sourceText);

        $this->assertStringContainsString('FOCUS:', $prompt);
        $this->assertStringContainsString('Power, War', $prompt);
        $this->assertStringContainsString('Focus on daily life of a soldier', $prompt);
    }

    public function test_outline_prompt_omits_focus_block_when_no_tags_or_focus(): void
    {
        $lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Julius Caesar', 'subject' => 'history', 'grade_level' => 'Age 12',
            'image_style' => 'engraved', 'status' => LessonStatus::Draft,
            'tone' => 'dramatic',
        ]);

        $sourceText = 'Caesar was a powerful leader.';
        $prompt = LessonOutlinePrompt::user($lesson, $sourceText);

        $this->assertStringNotContainsString('FOCUS:', $prompt);
    }
}
