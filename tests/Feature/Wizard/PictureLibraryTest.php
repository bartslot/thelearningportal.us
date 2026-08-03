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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The picker's Library shelf: pictures we already own — this lesson's own imagery plus our
 * Cloudinary collection. It is also the fallback when the corpus and Commons find nothing, which
 * is the whole point: a teacher must never face an empty modal in front of a collection we own.
 */
class PictureLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    private Scene $scene;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloudinary.url' => 'cloudinary://key:secret@democloud']);

        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Tasman', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);
        $this->scene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Golden Bay.', 'image_path' => 'lessons/1/own.jpg', 'status' => 'ready',
        ]);
    }

    private function fakeCloudinaryLibrary(): void
    {
        Http::fake([
            'api.cloudinary.com/*' => Http::response(['resources' => [[
                'public_id' => 'lessons/9/heemskerck',
                'secure_url' => 'https://res.cloudinary.com/democloud/image/upload/v1/lessons/9/heemskerck.jpg',
                'width' => 1600, 'height' => 900,
            ]]]),
        ]);
    }

    public function test_library_shelf_shows_this_lessons_images_and_our_cloudinary_collection(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lessons/1/own.jpg', 'jpeg-bytes');
        $this->fakeCloudinaryLibrary();

        $tiles = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('openImageLibrary')
            ->call('preparePaintings')
            ->set('paintingKind', 'library')
            ->instance()
            ->paintingResults;

        $this->assertCount(2, $tiles);
        $this->assertSame(['library', 'library'], $tiles->pluck('source')->all());
        // This lesson's own picture leads — the teacher just put it there.
        $this->assertStringContainsString('/storage/lessons/1/own.jpg', $tiles[0]['key']);
        $this->assertStringContainsString('heemskerck', $tiles[1]['key']);
    }

    public function test_a_library_pick_becomes_the_scene_background_without_leaving_our_storage(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lessons/1/own.jpg', 'jpeg-bytes');
        Http::fake();   // nothing may be downloaded: the picture is already on our disk

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('openPaintingPicker')
            ->call('selectScene', $this->scene->id)
            ->call('applyPaintingChoice', 'library', '/storage/lessons/1/own.jpg')
            ->assertSet('paintingPickerOpen', false);

        $this->assertSame('lessons/1/own.jpg', $this->scene->fresh()->image_path);
        Http::assertNothingSent();
    }

    /**
     * Voyage configs from earlier builders hold the bare disk path, not a URL. Treating one as a
     * URL sent the picker off to resolve a host called "lessons" and told the teacher the image
     * could not be used — while it sat on our own disk all along.
     */
    public function test_a_landfall_image_stored_as_a_bare_disk_path_is_read_from_our_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lessons/tasman/mauritius-voc.jpg', 'jpeg-bytes');
        Http::fake();
        $this->scene->update(['config' => ['stop_images' => ['lessons/tasman/mauritius-voc.jpg']]]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('openPaintingPicker')
            ->call('selectScene', $this->scene->id)
            ->call('applyPaintingChoice', 'library', 'lessons/tasman/mauritius-voc.jpg');

        $this->assertSame('lessons/tasman/mauritius-voc.jpg', $this->scene->fresh()->image_path);
        Http::assertNothingSent();
    }

    /**
     * Lesson builders ship imagery in public/lessons/<slug>/ — beside the public disk, not on it.
     * The picker offered those pictures and then refused every one of them.
     */
    public function test_a_picture_shipped_in_the_public_folder_is_copied_onto_the_disk(): void
    {
        Storage::fake('public');
        Http::fake();
        $shipped = public_path('lessons/tasman-test/vloot.jpg');
        @mkdir(dirname($shipped), 0755, true);
        file_put_contents($shipped, 'jpeg-bytes');
        $this->scene->update(['config' => ['stop_images' => ['/lessons/tasman-test/vloot.jpg']]]);

        try {
            Livewire::actingAs($this->teacher)
                ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
                ->call('openPaintingPicker')
                ->call('selectScene', $this->scene->id)
                ->call('applyPaintingChoice', 'library', '/lessons/tasman-test/vloot.jpg');

            $path = $this->scene->fresh()->image_path;
            $this->assertStringStartsWith("lessons/{$this->lesson->id}/uploads/reuse-", $path);
            $this->assertSame('jpeg-bytes', Storage::disk('public')->get($path));
            Http::assertNothingSent();
        } finally {
            @unlink($shipped);
            @rmdir(dirname($shipped));
        }
    }

    /**
     * (user, source, source_ref) is unique and SvgAsset soft-deletes, so the row of a deleted
     * picture still holds the key. Picking that picture again inserted a second row and died on
     * the constraint — a 500 in the middle of "add an image on the slide".
     */
    public function test_picking_a_picture_whose_layer_was_deleted_earlier_brings_it_back(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('lessons/1/own.jpg', 'jpeg-bytes');
        Http::fake();
        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('openImageLibrary')
            ->call('selectScene', $this->scene->id)
            ->call('applyPaintingChoice', 'library', '/storage/lessons/1/own.jpg');

        SvgAsset::where('source', 'library')->delete();   // teacher removes it from their library

        $component->call('openImageLibrary')
            ->call('applyPaintingChoice', 'library', '/storage/lessons/1/own.jpg')
            ->assertHasNoErrors();

        $this->assertSame(1, SvgAsset::where('source', 'library')->count(), 'the deleted row is reused, not duplicated');
        $assetId = SvgAsset::where('source', 'library')->value('id');
        $this->assertCount(1, collect($this->scene->fresh()->shots[0]['layers'])->where('asset_id', $assetId));
    }

    public function test_a_url_we_do_not_own_is_refused(): void
    {
        Http::fake();

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('openPaintingPicker')
            ->call('selectScene', $this->scene->id)
            ->call('applyPaintingChoice', 'library', 'http://169.254.169.254/latest/meta-data/');

        Http::assertNothingSent();
        $this->assertSame('lessons/1/own.jpg', $this->scene->fresh()->image_path);
    }

    public function test_an_empty_corpus_and_commons_search_falls_back_to_our_own_pictures(): void
    {
        $this->fakeCloudinaryLibrary();

        $tiles = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('openImageLibrary')
            ->call('preparePaintings')
            // A term no corpus or Commons search can answer, with Commons already exhausted.
            ->set('paintingQuery', 'zzzzqqqxx')
            ->set('paintingCommonsLoaded', true)
            ->instance()
            ->paintingResults;

        $this->assertNotEmpty($tiles, 'an owned collection must never render as an empty grid');
        $this->assertSame('library', $tiles[0]['source']);
    }
}
