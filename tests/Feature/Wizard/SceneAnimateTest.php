<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\SvgAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Animate tab: how a layer arrives, and how a scene replaces the one before it.
 *
 * The option lists live in three places — this whitelist, the Blade picker, and
 * resources/js/scene/animations.js which plays them. If they drift, a teacher picks something the
 * server rejects and the panel silently does nothing, so the values are pinned here.
 */
class SceneAnimateTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    private Scene $scene;

    private SvgAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);

        $this->asset = SvgAsset::create([
            'user_id' => $this->teacher->id,
            'source' => 'commons', 'source_ref' => 'anim-1',
            'source_url' => 'https://example.com/a.svg',
            'title' => 'A', 'license' => 'CC0', 'svg_path' => 'assets/a.svg',
        ]);

        $this->scene = Scene::create([
            'lesson_id' => $this->lesson->id,
            'order' => 1, 'kind' => 'narration', 'status' => 'ready',
            'image_path' => 'bg.png',
            'shots' => [['order' => 0, 'image_path' => 'bg.png', 'layers' => [
                ['path' => 'bg.png', 'kind' => 'cover', 'depth' => 0.4],
                ['asset_id' => $this->asset->id, 'path' => 'assets/a.svg', 'kind' => 'figure', 'x' => 50.0, 'y' => 58.0, 'scale' => 1.0, 'height' => 40],
            ]]],
        ]);
    }

    private function wizard()
    {
        return Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id);
    }

    private function layer(): array
    {
        return collect($this->scene->refresh()->shots[0]['layers'])->firstWhere('asset_id', $this->asset->id);
    }

    public function test_a_layer_remembers_how_it_arrives(): void
    {
        $this->wizard()
            ->call('updateArtworkLayer', $this->asset->id, 'anim', 'slide-left')
            ->call('updateArtworkLayer', $this->asset->id, 'anim_delay', '1.5')
            ->call('updateArtworkLayer', $this->asset->id, 'anim_ease', 'pop');

        $layer = $this->layer();

        $this->assertSame('slide-left', $layer['anim']);
        $this->assertSame(1.5, $layer['anim_delay']);
        $this->assertSame('pop', $layer['anim_ease']);
    }

    public function test_an_entrance_the_player_cannot_play_is_refused(): void
    {
        $this->wizard()
            ->call('updateArtworkLayer', $this->asset->id, 'anim', 'backflip')
            ->call('updateArtworkLayer', $this->asset->id, 'anim_ease', 'bouncy');

        $layer = $this->layer();

        $this->assertArrayNotHasKey('anim', $layer);
        $this->assertArrayNotHasKey('anim_ease', $layer);
    }

    /** A delay measured in minutes is a mistake, not a choice. */
    public function test_the_delay_is_clamped_to_something_a_lesson_can_wait_for(): void
    {
        $this->wizard()->call('updateArtworkLayer', $this->asset->id, 'anim_delay', '99');

        // A whole-number float comes back from the JSON column as an int, so compare numerically.
        $this->assertSame(10.0, (float) $this->layer()['anim_delay']);
    }

    public function test_a_scene_remembers_how_it_replaces_the_one_before_it(): void
    {
        $this->wizard()
            ->call('setSceneTransition', 'type', 'slide-up')
            ->call('setSceneTransition', 'duration', '1.2')
            ->call('setSceneTransition', 'ease', 'pop');

        $transition = $this->scene->refresh()->config['transition'];

        $this->assertSame('slide-up', $transition['type']);
        $this->assertSame(1.2, $transition['duration']);
        $this->assertSame('pop', $transition['ease']);
    }

    public function test_an_unknown_transition_leaves_the_scene_as_it_was(): void
    {
        $this->wizard()
            ->call('setSceneTransition', 'type', 'slide-up')
            ->call('setSceneTransition', 'type', 'dissolve-into-stars');

        $this->assertSame('slide-up', $this->scene->refresh()->config['transition']['type']);
    }

    /** Long enough and it stops reading as a transition and starts reading as a stall. */
    public function test_the_transition_time_is_clamped(): void
    {
        $this->wizard()->call('setSceneTransition', 'duration', '30');

        $this->assertSame(3.0, (float) $this->scene->refresh()->config['transition']['duration']);
    }

    /**
     * setSceneTransition writes through the config SNAPSHOT that saveSelected rebuilds from — a
     * mutator that writes straight to the model is undone by the next save from that snapshot.
     */
    public function test_setting_a_transition_does_not_wipe_the_rest_of_the_config(): void
    {
        $this->scene->update(['config' => ['background_fit' => 'contain', 'clipart_on_top' => true]]);

        $this->wizard()->call('setSceneTransition', 'type', 'crossfade');

        $config = $this->scene->refresh()->config;

        $this->assertSame('contain', $config['background_fit']);
        $this->assertTrue($config['clipart_on_top']);
        $this->assertSame('crossfade', $config['transition']['type']);
    }
}
