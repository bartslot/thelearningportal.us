<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Livewire\Wizard\Step4Preview;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Segment 4 of the game-story (spel-verhaal) build: Step 3 authoring panels
 * (branch-option game effects + lesson meters) and the publish completeness guard.
 * Plan: docs/superpowers/plans/2026-07-09-game-story-lesson.md
 */
class StoryGameAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    private Scene $question;

    private Scene $optionA;

    private Scene $optionB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
            'game_type' => 'story_game',
            'game_config' => [
                'print_pack' => true,
                'fail_threshold' => 0,
                'roles' => [['title' => 'Generaal', 'flavor' => 'f', 'power' => 'p']],
                'meters' => [
                    ['key' => 'morale', 'label' => 'Moreel', 'icon' => '🔥', 'start' => 60],
                    ['key' => 'troops', 'label' => 'Manschappen', 'icon' => '👥', 'start' => 70],
                ],
            ],
        ]);

        $this->question = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'branch_group' => 1, 'branch_role' => 'question',
            'script_segment' => 'Choose!', 'status' => 'ready',
        ]);
        $this->optionA = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'narration',
            'branch_group' => 1, 'branch_role' => 'option_a', 'branch_choice_label' => 'Attack',
            'config' => ['texts' => [['id' => 'txt_1', 'text' => 'Keep me']]],
            'script_segment' => 'Attack.', 'status' => 'ready',
        ]);
        $this->optionB = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'narration',
            'branch_group' => 1, 'branch_role' => 'option_b', 'branch_choice_label' => 'Wait',
            'script_segment' => 'Wait.', 'status' => 'ready',
        ]);
    }

    // ── Panels render ─────────────────────────────────────────────────────

    public function test_inspector_shows_game_effects_panel_only_for_option_scenes(): void
    {
        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson]);

        // Mount selects the question scene: meters panel yes, effects panel no.
        $component->assertSee(__('Class meters'))
            ->assertDontSee(__('Game effects'));

        $component->call('selectScene', $this->optionA->id)
            ->assertSee(__('Game effects'))
            ->assertSee('Moreel');
    }

    // ── saveBranchEffects ─────────────────────────────────────────────────

    public function test_save_branch_effects_persists_clamps_and_drops_unknown_meter_keys(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('saveBranchEffects', $this->optionA->id, [
                'deltas' => ['morale' => -40, 'troops' => '10', 'bogus' => 25],
                'consequence_line' => '  <b>De cavalerie stormt vooruit.</b>  ',
                'historical_note' => str_repeat('x', 300),
                'branch_choice_label' => ' <i>Stuur de cavalerie</i> ',
            ]);

        $effects = $this->optionA->fresh()->config['branch_effects'];

        $this->assertSame(-25, $effects['deltas']['morale'], 'delta clamped to −25');
        $this->assertSame(10, $effects['deltas']['troops']);
        $this->assertArrayNotHasKey('bogus', $effects['deltas'], 'unknown meter keys dropped');
        $this->assertSame('De cavalerie stormt vooruit.', $effects['consequence_line']);
        $this->assertSame(240, mb_strlen($effects['historical_note']));
        $this->assertSame('Stuur de cavalerie', $this->optionA->fresh()->branch_choice_label);
    }

    public function test_save_branch_effects_preserves_other_config_keys(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('saveBranchEffects', $this->optionA->id, [
                'deltas' => ['morale' => 5],
                'consequence_line' => 'Line', 'historical_note' => 'Note', 'branch_choice_label' => 'Attack',
            ]);

        $config = $this->optionA->fresh()->config;

        $this->assertSame('Keep me', $config['texts'][0]['text'], 'pre-existing config keys survive');
        $this->assertSame(5, $config['branch_effects']['deltas']['morale']);
    }

    public function test_save_branch_effects_ignores_non_option_scenes(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('saveBranchEffects', $this->question->id, [
                'deltas' => ['morale' => 5],
            ]);

        $this->assertArrayNotHasKey('branch_effects', $this->question->fresh()->config ?? []);
    }

    public function test_save_branch_effects_ignores_non_story_game_lessons(): void
    {
        $this->lesson->update(['game_type' => 'quiz']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('saveBranchEffects', $this->optionA->id, [
                'deltas' => ['morale' => 5],
            ]);

        $this->assertArrayNotHasKey('branch_effects', $this->optionA->fresh()->config ?? []);
    }

    // ── Ownership ─────────────────────────────────────────────────────────

    public function test_non_owner_cannot_mount_the_configurator(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->assertForbidden();
    }

    public function test_save_branch_effects_cannot_touch_another_lessons_scene(): void
    {
        $foreignScene = Scene::create([
            'lesson_id' => Lesson::create([
                'teacher_id' => User::factory()->create()->id,
                'topic' => 'Rome', 'subject' => 'history', 'grade_level' => '8',
                'game_type' => 'story_game',
            ])->id,
            'order' => 1, 'kind' => 'narration',
            'branch_group' => 1, 'branch_role' => 'option_a',
            'script_segment' => 'x', 'status' => 'ready',
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('saveBranchEffects', $foreignScene->id, ['deltas' => ['morale' => 5]]);
    }

    // ── saveMeters ────────────────────────────────────────────────────────

    public function test_save_meters_validates_ranges_and_keeps_keys_immutable(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('saveMeters', [
                ['key' => 'hacked', 'label' => '<b>Strijdlust van het hele leger</b>', 'icon' => '⚔️', 'start' => 250],
                ['key' => 'troops', 'label' => 'Soldaten', 'icon' => '', 'start' => -5],
            ]);

        $meters = $this->lesson->fresh()->game_config['meters'];

        $this->assertSame('morale', $meters[0]['key'], 'meter keys are immutable');
        $this->assertSame('Strijdlust van het hele ', $meters[0]['label'], 'label tag-stripped + capped at 24 chars');
        $this->assertSame('⚔️', $meters[0]['icon']);
        $this->assertSame(100, $meters[0]['start'], 'start clamped to 100');
        $this->assertSame('troops', $meters[1]['key']);
        $this->assertSame('👥', $meters[1]['icon'], 'blank icon keeps the old one');
        $this->assertSame(0, $meters[1]['start'], 'start clamped to 0');
        $this->assertCount(2, $meters, 'no meters added or removed');
    }

    public function test_save_meters_preserves_other_game_config_keys(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('saveMeters', [
                ['key' => 'morale', 'label' => 'Moreel', 'icon' => '🔥', 'start' => 50],
                ['key' => 'troops', 'label' => 'Manschappen', 'icon' => '👥', 'start' => 70],
            ]);

        $config = $this->lesson->fresh()->game_config;

        $this->assertTrue($config['print_pack']);
        $this->assertSame(0, $config['fail_threshold']);
        $this->assertSame('Generaal', $config['roles'][0]['title']);
        $this->assertSame(50, $config['meters'][0]['start']);
    }

    public function test_save_meters_ignores_non_story_game_lessons(): void
    {
        $this->lesson->update(['game_type' => 'quiz']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('saveMeters', [
                ['key' => 'morale', 'label' => 'Changed', 'icon' => '🔥', 'start' => 10],
            ]);

        $this->assertSame('Moreel', $this->lesson->fresh()->game_config['meters'][0]['label']);
    }

    // ── Publish guard ─────────────────────────────────────────────────────

    public function test_publish_blocks_story_game_with_incomplete_branch_options(): void
    {
        // optionA gets effects, optionB stays empty → group 1 incomplete.
        $this->optionA->update(['config' => array_merge($this->optionA->config ?? [], [
            'branch_effects' => ['deltas' => ['morale' => -10, 'troops' => 0], 'consequence_line' => 'x', 'historical_note' => 'y'],
        ])]);

        $component = Livewire::actingAs($this->teacher)
            ->test(Step4Preview::class, ['lesson' => $this->lesson])
            ->call('publish');

        $component->assertSet('showPublishSplash', false);
        $this->assertNotSame(LessonStatus::Published, $this->lesson->fresh()->status);
        $this->assertStringContainsString('1', $component->get('publishError'), 'error names the incomplete group');
    }

    public function test_publish_allows_a_complete_story_game(): void
    {
        $effects = ['deltas' => ['morale' => -10, 'troops' => 5], 'consequence_line' => 'x', 'historical_note' => 'y'];
        $this->optionA->update(['config' => array_merge($this->optionA->config ?? [], ['branch_effects' => $effects])]);
        $this->optionB->update(['config' => ['branch_effects' => $effects]]);

        Livewire::actingAs($this->teacher)
            ->test(Step4Preview::class, ['lesson' => $this->lesson])
            ->call('publish')
            ->assertSet('publishError', '')
            ->assertSet('showPublishSplash', true);

        $this->assertSame(LessonStatus::Published, $this->lesson->fresh()->status);
    }

    public function test_publish_ignores_the_guard_for_non_story_game_lessons(): void
    {
        // Same incomplete branch scenes, but a plain quiz lesson — guard must not fire.
        $this->lesson->update(['game_type' => 'quiz']);

        Livewire::actingAs($this->teacher)
            ->test(Step4Preview::class, ['lesson' => $this->lesson])
            ->call('publish')
            ->assertSet('publishError', '');

        $this->assertSame(LessonStatus::Published, $this->lesson->fresh()->status);
    }
}
