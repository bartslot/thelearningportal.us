<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dragging a picture straight from the desktop onto a scene.
 *
 * Two targets, and they mean different things. The BACKGROUND SLOT in the inspector always sets the
 * background, because the teacher pointed at the background. The CANVAS is ambiguous, so the server
 * decides: a scene that already has a backdrop gets the picture as a layer on top, a scene with none
 * gets it as the background. Bart: "if there's already a background add the layer, if there's no
 * background add it as a background" and "not convert the scene".
 */
class Step3ImageDropTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->teacher = User::create([
            'name' => 'Teacher', 'email' => 'teacher@example.com',
            'password' => 'secret-password', 'role' => 'teacher',
        ]);

        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'title' => 'Dropped pictures',
            'topic' => 'Dropped pictures',
            'subject' => 'history',
            'grade_level' => '7',
            'status' => LessonStatus::Configuring,
        ]);
    }

    private function scene(array $attributes = []): Scene
    {
        return Scene::create([
            'lesson_id' => $this->lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'ready',
            'script' => 'Once upon a time.',
            ...$attributes,
        ]);
    }

    private function wizardOn(Scene $scene): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $scene->id);
    }

    public function test_a_drop_on_a_scene_with_no_background_becomes_the_background(): void
    {
        $scene = $this->scene();
        $this->assertFalse($scene->hasBackdrop());

        $this->wizardOn($scene)->set('droppedImage', UploadedFile::fake()->image('rome.jpg', 1200, 800));

        $scene->refresh();
        $this->assertNotNull($scene->image_path);
        Storage::disk('public')->assertExists($scene->image_path);
    }

    public function test_a_drop_on_a_scene_that_already_has_one_does_not_replace_it(): void
    {
        // The whole point of the rule: a second picture is a layer on top, not a swap. Losing the
        // background a teacher chose because they dragged in a second image would be destructive.
        $scene = $this->scene(['image_path' => 'lessons/1/scenes/1/bg.jpg']);
        $this->assertTrue($scene->hasBackdrop());

        $this->wizardOn($scene)->set('droppedImage', UploadedFile::fake()->image('Hannibal crossing.jpg', 800, 600));

        $this->assertSame('lessons/1/scenes/1/bg.jpg', $scene->refresh()->image_path);

        // The layer is named after the teacher's own file. Laravel stores it under a random hash,
        // and a hash is what the object list would otherwise show them.
        $this->assertDatabaseHas('svg_assets', ['title' => 'Hannibal crossing', 'source' => 'library']);
    }

    public function test_a_drop_on_a_map_scene_leaves_the_map_alone(): void
    {
        // A map draws its own backdrop and carries no image_path, so asking "is image_path empty?"
        // would have replaced the map with a still picture and turned the scene into a slideshow.
        // hasBackdrop() is the question that gets this right.
        $scene = $this->scene(['kind' => 'map', 'config' => ['year' => 1600]]);
        $this->assertTrue($scene->hasBackdrop(), 'a map scene draws its own backdrop');

        $wizard = $this->wizardOn($scene);
        $viewBeforeDrop = $scene->refresh()->scene_view;   // selecting a scene already normalises this

        $wizard->set('droppedImage', UploadedFile::fake()->image('alps.jpg', 900, 600));

        $scene->refresh();
        $this->assertNull($scene->image_path);
        $this->assertSame('map', $scene->kind);
        $this->assertSame($viewBeforeDrop, $scene->scene_view, 'the drop must not change how the scene renders');
    }

    public function test_the_background_slot_always_sets_the_background(): void
    {
        // Same scene as the "do not replace" case above, but dropped on the background slot rather
        // than the canvas. Pointing at the background is not ambiguous.
        $scene = $this->scene(['image_path' => 'lessons/1/scenes/1/bg.jpg']);

        $this->wizardOn($scene)->set('droppedBackground', UploadedFile::fake()->image('new-bg.jpg', 1200, 800));

        $scene->refresh();
        $this->assertNotSame('lessons/1/scenes/1/bg.jpg', $scene->image_path);
        Storage::disk('public')->assertExists($scene->image_path);
    }

    public function test_a_dropped_background_clears_a_3d_or_video_embed(): void
    {
        $scene = $this->scene(['config' => ['bg_embed' => ['kind' => 'sketchfab', 'id' => 'abc', 'src' => 'https://sketchfab.com/models/abc/embed']]]);

        $this->wizardOn($scene)->set('droppedBackground', UploadedFile::fake()->image('flat.jpg'));

        $this->assertArrayNotHasKey('bg_embed', $scene->refresh()->config ?? []);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $scene = $this->scene();

        $this->wizardOn($scene)
            ->set('droppedImage', UploadedFile::fake()->create('lesson-plan.pdf', 40, 'application/pdf'))
            ->assertDispatched('toast');

        $this->assertNull($scene->refresh()->image_path);
    }

    public function test_a_sticky_painting_picker_mode_cannot_divert_a_drop(): void
    {
        // paintingPickerMode keeps whatever the last opened picker set. Routing drops through the
        // modal's own upload path would have sent this picture to the gallery instead, and the
        // teacher would have watched a drop on the canvas do nothing they asked for.
        $scene = $this->scene();

        $this->wizardOn($scene)
            ->set('paintingPickerMode', 'gallery_image')
            ->set('droppedImage', UploadedFile::fake()->image('rome.jpg'));

        $this->assertNotNull($scene->refresh()->image_path);
    }

    public function test_a_drop_with_no_scene_selected_says_so_instead_of_doing_nothing(): void
    {
        $this->scene();

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->set('selectedSceneId', null)
            ->set('droppedImage', UploadedFile::fake()->image('rome.jpg'))
            ->assertDispatched('toast');
    }
}
