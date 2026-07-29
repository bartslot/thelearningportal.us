<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hand-authored branching.
 *
 * BuildLessonOutline used to be the only code that ever wrote branch_group/branch_role, so a
 * branching story was impossible without a working LLM — and with the writing service out of credit,
 * impossible full stop. These cover the manual route.
 */
class AddBranchSceneTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'The Dutch Revolt', 'subject' => 'history', 'grade_level' => 'Age 12',
            'image_style' => 'realistic', 'status' => LessonStatus::ScenesReady,
        ]);
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Opening.', 'image_path' => 'a.png', 'audio_path' => 'a.mp3',
            'status' => 'ready',
        ]);
    }

    private function addBranch(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('addScene', 'branch');
    }

    public function test_adding_a_branch_creates_a_question_and_two_options_in_one_group(): void
    {
        $this->addBranch();

        $branch = $this->lesson->scenes()->whereNotNull('branch_group')->ordered()->get();

        $this->assertCount(3, $branch);
        $this->assertSame(['question', 'option_a', 'option_b'], $branch->pluck('branch_role')->all());
        $this->assertSame([1, 1, 1], $branch->pluck('branch_group')->map(fn ($g) => (int) $g)->all());
        // Options carry a starting choice label; the question does not.
        $this->assertNull($branch[0]->branch_choice_label);
        $this->assertNotEmpty($branch[1]->branch_choice_label);
        $this->assertNotEmpty($branch[2]->branch_choice_label);
    }

    /** Structurally identical to what the generator emits, so every reader treats them the same. */
    public function test_branch_scenes_are_narration_scenes_like_the_generated_ones(): void
    {
        $this->addBranch();

        foreach ($this->lesson->scenes()->whereNotNull('branch_group')->get() as $scene) {
            $this->assertSame('narration', $scene->kind);
            $this->assertSame('pending', $scene->status);
        }
    }

    /**
     * The effects panel only opens for option scenes of a story_game, and the deltas it edits are
     * keyed on lesson-level meters. Without both, the teacher gets option scenes whose effects can
     * never be set — and the publish gate demands deltas on every option, so the lesson would be
     * permanently unpublishable. This is the trap the hand-added quiz scene fell into before.
     */
    public function test_adding_a_branch_promotes_the_lesson_and_seeds_meters(): void
    {
        $this->assertNotSame('story_game', $this->lesson->game_type);

        $this->addBranch();
        $lesson = $this->lesson->fresh();

        $this->assertSame('story_game', $lesson->game_type);
        $this->assertTrue((bool) $lesson->include_game);
        $this->assertNotEmpty($lesson->game_config['meters'] ?? []);

        foreach ($lesson->game_config['meters'] as $meter) {
            $this->assertArrayHasKey('key', $meter);
            $this->assertArrayHasKey('label', $meter);
            $this->assertIsInt($meter['start']);
        }
    }

    /** The effects panel must actually be reachable for the options that were just created. */
    public function test_the_game_effects_panel_opens_for_a_hand_made_option_scene(): void
    {
        $this->addBranch();

        $option = $this->lesson->scenes()->where('branch_role', 'option_a')->firstOrFail();
        $question = $this->lesson->scenes()->where('branch_role', 'question')->firstOrFail();

        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson->fresh()]);

        $this->assertTrue($component->instance()->showsGameEffectsPanel($option));
        $this->assertFalse($component->instance()->showsGameEffectsPanel($question));
        $this->assertTrue($component->instance()->showsMetersPanel());
    }

    /** Effects saved on a hand-made option persist — the panel is wired end to end. */
    public function test_effects_can_be_saved_on_a_hand_made_option(): void
    {
        $this->addBranch();
        $option = $this->lesson->scenes()->where('branch_role', 'option_a')->firstOrFail();
        $meterKey = $this->lesson->fresh()->game_config['meters'][0]['key'];

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson->fresh()])
            ->call('saveBranchEffects', $option->id, [
                'deltas' => [$meterKey => 10],
                'consequence_line' => 'The city holds.',
                'branch_choice_label' => 'Hold the city',
            ]);

        $effects = $option->fresh()->config['branch_effects'] ?? [];
        $this->assertSame(10, $effects['deltas'][$meterKey] ?? null);
    }

    public function test_a_second_branch_gets_its_own_group(): void
    {
        $this->addBranch();
        $this->addBranch();

        $groups = $this->lesson->scenes()->whereNotNull('branch_group')
            ->pluck('branch_group')->map(fn ($g) => (int) $g)->unique()->sort()->values()->all();

        $this->assertSame([1, 2], $groups);
        $this->assertSame(6, $this->lesson->scenes()->whereNotNull('branch_group')->count());
    }

    /** Same ceiling the generator obeys, so a hand-built story can't outgrow the player. */
    public function test_branches_are_capped_at_three_groups(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->addBranch();
        }

        $this->assertSame(3, $this->lesson->scenes()->whereNotNull('branch_group')
            ->distinct()->count('branch_group'));
        $this->assertSame(9, $this->lesson->scenes()->whereNotNull('branch_group')->count());
    }

    /** The trio must stay contiguous and in role order after the selected scene. */
    public function test_the_three_scenes_are_kept_together_in_order(): void
    {
        $this->addBranch();

        $roles = $this->lesson->scenes()->ordered()->get()
            ->filter(fn (Scene $s) => $s->branch_role !== null)
            ->values()->pluck('branch_role')->all();

        $this->assertSame(['question', 'option_a', 'option_b'], $roles);
    }

    /**
     * The payoff: a branch built entirely by hand must be publishable once the teacher fills it in.
     * If it were not, the Add-scene tile would just be a new way to make a permanently blocked
     * lesson. Blocked first (options have no deltas), then live after both are set.
     */
    public function test_a_hand_built_branching_lesson_can_be_published(): void
    {
        $this->addBranch();
        $lesson = $this->lesson->fresh();
        $meterKey = $lesson->game_config['meters'][0]['key'];

        // The teacher writes and narrates the three scenes.
        $lesson->scenes()->whereNotNull('branch_group')->get()
            ->each(fn (Scene $s) => $s->update([
                'script_segment' => 'Written by hand.', 'image_path' => 'b.png',
                'audio_path' => 'b.mp3', 'status' => 'ready',
            ]));

        $preview = fn () => Livewire::actingAs($this->teacher)
            ->test(\App\Livewire\Wizard\Step4Preview::class, ['lesson' => $lesson->fresh()]);

        // Options still carry no meter effects → the story-game gate holds the lesson back.
        $blocked = $preview()->call('publish');
        $this->assertNotSame(LessonStatus::Published, $lesson->fresh()->status);
        $blocked->assertSet('publishError', fn ($e) => str_contains((string) $e, 'Story game incomplete'));

        // Fill both options in, exactly as the effects panel does.
        $configurator = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson->fresh()]);
        foreach (['option_a', 'option_b'] as $role) {
            $scene = $lesson->scenes()->where('branch_role', $role)->firstOrFail();
            $configurator->call('saveBranchEffects', $scene->id, [
                'deltas' => [$meterKey => $role === 'option_a' ? 10 : -10],
                'consequence_line' => 'A consequence.',
                'branch_choice_label' => 'A choice',
            ]);
        }

        $preview()->call('publish');
        $this->assertSame(LessonStatus::Published, $lesson->fresh()->status);
    }

    /** Branching makes no sense on a voyage lesson, whose spine is the route. */
    public function test_branch_is_refused_on_a_voyage_lesson(): void
    {
        $this->lesson->update(['game_type' => 'voyage']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson->fresh()])
            ->call('addScene', 'branch');

        $this->assertSame(0, $this->lesson->scenes()->whereNotNull('branch_group')->count());
        $this->assertSame('voyage', $this->lesson->fresh()->game_type);
    }
}
