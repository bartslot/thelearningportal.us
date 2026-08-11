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
 * A layer does not need a backdrop underneath it.
 *
 * Adding a picture used to be refused unless the scene already had one, and that check was wrong in
 * three ways at once. Icons reach `attachArtwork()` directly and were never checked, so the same
 * product refused a painting and accepted a camel. A slideshow scene shows its pictures without
 * carrying an `image_path`, so a teacher looking straight at artwork was told to "generate a scene
 * background first" — twice, in Bart's screenshot. And placing the figure before the backdrop is an
 * ordinary way to build a scene.
 */
class LayersNeedNoBackdropTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::create([
            'name' => 'Teacher', 'email' => 'teacher@example.com',
            'password' => 'secret-password', 'role' => 'teacher',
        ]);

        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id, 'title' => 'Silk Road', 'topic' => 'Silk Road',
            'subject' => 'history', 'grade_level' => '7', 'status' => LessonStatus::Configuring,
        ]);
    }

    /** A scene with nothing behind it: no image, no map, no colour. The hardest case. */
    private function bareScene(): Scene
    {
        return Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'status' => 'ready', 'script' => 'Camels crossed the Taklamakan.',
        ]);
    }

    private function icon(): SvgAsset
    {
        return SvgAsset::create([
            'user_id' => null, 'source' => 'library', 'source_ref' => 'camel',
            'title' => 'Camel', 'svg_path' => 'icons/transport/camel.svg', 'license' => 'own',
            'source_url' => 'icons/transport/camel.svg',
        ]);
    }

    public function test_an_icon_lands_on_a_scene_with_no_backdrop(): void
    {
        $scene = $this->bareScene();
        $this->assertFalse($scene->hasBackdrop(), 'the case the guard was refusing');

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $scene->id)
            ->call('attachArtwork', $this->icon()->id);

        $layers = $scene->refresh()->shots[0]['layers'] ?? [];
        $this->assertCount(1, $layers);
    }

    public function test_the_same_scene_no_longer_refuses_a_picture(): void
    {
        // The inconsistency in one test: an icon was always allowed on this scene and a picture was
        // not, which is the same action to a teacher.
        $scene = $this->bareScene();

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $scene->id)
            ->call('attachArtwork', $this->icon()->id)
            ->assertNotDispatched('toast');
    }

    public function test_a_slideshow_scene_showing_pictures_is_not_told_to_make_a_background(): void
    {
        // Bart's actual scene. It has artwork on screen and no image_path, so hasBackdrop() was
        // false and the toast fired while a painting filled the canvas behind it.
        $scene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'narration',
            'status' => 'ready', 'script' => 'Dunhuang.', 'scene_view' => 'slideshow',
            'shots' => [['image_path' => 'lessons/1/scenes/2/shot_0.webp', 'layers' => []]],
        ]);
        $this->assertFalse($scene->hasBackdrop(), 'pictures on screen, no image_path');

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $scene->id)
            ->call('attachArtwork', $this->icon()->id)
            ->assertNotDispatched('toast');

        $this->assertNotEmpty($scene->refresh()->shots[0]['layers'] ?? []);
    }
}
