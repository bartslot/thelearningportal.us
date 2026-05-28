<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\StrategyGame;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class Step3SceneConfiguratorTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;
    private Scene $s1;
    private Scene $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson  = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);
        $this->s1 = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'year' => '1810', 'location' => 'Paris', 'script_segment' => 'Old script.',
            'image_path' => 'a.png', 'audio_path' => 'a.mp3', 'audio_script_hash' => sha1('Old script.'),
            'status' => 'ready',
        ]);
        $this->s2 = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'game',
            'game_segment_index' => 1, 'duration_seconds' => 480,
            'script_segment' => 'Game intro.', 'image_path' => 'g.png', 'audio_path' => 'g.mp3',
            'status' => 'ready',
        ]);
    }

    public function test_selects_the_first_scene_on_mount(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->assertSet('selectedSceneId', $this->s1->id);
    }

    public function test_updates_scene_fields_via_inspector(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->set('selectedSceneId', $this->s1->id)
            ->set('selectedScene.year', '1811')
            ->set('selectedScene.location', 'Versailles')
            ->call('saveSelected');

        $this->s1->refresh();
        $this->assertSame('1811', $this->s1->year);
        $this->assertSame('Versailles', $this->s1->location);
    }

    public function test_reorders_scenes(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('reorder', [$this->s2->id, $this->s1->id]);

        $this->assertSame(2, $this->s1->fresh()->order);
        $this->assertSame(1, $this->s2->fresh()->order);
    }

    public function test_regenerates_a_single_asset_via_dispatch(): void
    {
        Bus::fake();
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('regenerate', $this->s1->id, 'image');
        Bus::assertDispatched(GenerateSceneImage::class, fn ($j) => $j->sceneId === $this->s1->id);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('regenerate', $this->s1->id, 'audio');
        Bus::assertDispatched(GenerateSceneAudio::class, fn ($j) => $j->sceneId === $this->s1->id);
    }

    public function test_adds_a_blank_narration_scene_at_the_end(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('addScene');

        $this->assertSame(3, $this->lesson->scenes()->count());
        $this->assertSame('narration', Scene::orderBy('order', 'desc')->first()->kind);
    }

    public function test_adds_game_scene_with_selected_game_type_defaults(): void
    {
        $game = StrategyGame::create([
            'slug' => 'strategy',
            'title' => 'Strategy',
            'description' => 'x',
            'subject' => 'History',
            'topic_keywords' => [],
            'instructions' => 'i',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('addScene', 'game', 'strategy');

        $scene = Scene::orderBy('order', 'desc')->first();
        $this->assertSame('game', $scene->kind);
        $this->assertSame('strategy', $scene->game_type);
        $this->assertSame($game->id, $scene->strategy_game_id);
        $this->assertSame(600, $scene->duration_seconds);
        $this->assertTrue($this->lesson->fresh()->include_game);
    }

    public function test_changes_game_scene_type_and_resets_type_specific_settings(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('setSceneGameType', $this->s2->id, 'quiz');

        $scene = $this->s2->fresh();
        $this->assertSame('quiz', $scene->game_type);
        $this->assertSame(4, $scene->quiz_question_count);
        $this->assertSame('after', $scene->quiz_timing);
        $this->assertNull($scene->strategy_game_id);
    }

    public function test_deletes_a_scene_and_reindexes_game_segments(): void
    {
        $g2 = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'game',
            'game_segment_index' => 2, 'status' => 'ready',
        ]);
        $this->lesson->update(['game_split_count' => 2]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('deleteScene', $this->s2->id);

        $this->assertNull(Scene::find($this->s2->id));
        $this->assertSame(1, $g2->fresh()->game_segment_index);
        $this->assertSame(1, $this->lesson->fresh()->game_split_count);
    }
}
