<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\NarrativeFramework;
use App\Livewire\Wizard\Step2Story;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\StorySpine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Segment 1 of the game-story (spel-verhaal) build: schema, spine, Step 2 toggle,
 * player payload. Plan: docs/superpowers/plans/2026-07-09-game-story-lesson.md
 */
class StoryGameFoundationTest extends TestCase
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
            'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8',
        ]);
        // generate() starts the pipeline, which requires an attached source.
        \App\Models\LessonSource::create([
            'lesson_id' => $this->lesson->id, 'kind' => 'wikipedia',
            'extracted_text' => 'Napoleon facts.', 'wikipedia_topic' => 'Napoleon',
        ]);
    }

    public function test_game_config_round_trips_as_array(): void
    {
        $this->lesson->update(['game_config' => ['print_pack' => true, 'meters' => [['key' => 'morale']]]]);

        $fresh = $this->lesson->fresh();
        $this->assertTrue($fresh->game_config['print_pack']);
        $this->assertSame('morale', $fresh->game_config['meters'][0]['key']);
    }

    public function test_story_game_spine_has_three_choice_beats(): void
    {
        $spine = StorySpine::for(NarrativeFramework::Branching, 'story_game');

        $this->assertSame(3, count(array_keys($spine->beatTemplate, 'Choice', true)));
        $this->assertContains('Climax', $spine->beatTemplate);
    }

    public function test_plain_branching_spine_is_unchanged_without_story_game(): void
    {
        $spine = StorySpine::for(NarrativeFramework::Branching);

        $this->assertSame(['Setup', 'Approach', 'Choice', 'Rejoin', 'End'], $spine->beatTemplate);
    }

    public function test_step2_toggle_persists_story_game_type_and_print_pack(): void
    {
        Bus::fake();

        Livewire::actingAs($this->teacher)
            ->test(Step2Story::class, ['lesson' => $this->lesson])
            ->call('selectFramework', 'branching')
            ->set('story_game', true)
            ->set('print_pack', true)
            ->call('generate');

        $fresh = $this->lesson->fresh();
        $this->assertSame('story_game', $fresh->game_type);
        $this->assertTrue((bool) $fresh->include_game);
        $this->assertTrue($fresh->game_config['print_pack']);
    }

    public function test_switching_arc_away_from_branching_drops_story_game(): void
    {
        Bus::fake();

        Livewire::actingAs($this->teacher)
            ->test(Step2Story::class, ['lesson' => $this->lesson])
            ->call('selectFramework', 'branching')
            ->set('story_game', true)
            ->call('selectFramework', 'heros_journey')
            ->call('generate');

        $this->assertNotSame('story_game', $this->lesson->fresh()->game_type);
    }

    public function test_player_payload_exposes_branch_fields_and_game_config(): void
    {
        $this->lesson->update([
            'status' => \App\Enums\LessonStatus::Published,
            'game_type' => 'story_game',
            'game_config' => ['meters' => [['key' => 'morale', 'label' => 'Moreel', 'start' => 70]]],
        ]);
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'branch_group' => 1, 'branch_role' => 'option_a', 'branch_choice_label' => 'Stuur de cavalerie',
            'config' => ['branch_effects' => ['deltas' => ['morale' => -10]]],
            'status' => 'ready', 'script_segment' => 'x',
        ]);

        $response = $this->get('/lesson/'.$this->lesson->lesson_code);

        $response->assertOk()
            ->assertSee('branch_choice_label', false)
            ->assertSee('Stuur de cavalerie', false)
            ->assertSee('"branch_group":1', false)
            ->assertSee('"branch_role":"option_a"', false)
            ->assertSee('Moreel', false);
    }
}
