<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\SvgAsset;
use App\Models\User;
use App\Services\CommonsImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SceneArtworkTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $otherTeacher;

    private Lesson $lesson;

    private Scene $scene;

    private SvgAsset $asset1;

    private SvgAsset $asset2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->otherTeacher = User::factory()->create();

        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);

        $this->scene = Scene::create([
            'lesson_id' => $this->lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'year' => '1810',
            'location' => 'Paris',
            'script_segment' => 'Test script.',
            'image_path' => 'test-bg.png',
            'audio_path' => 'test.mp3',
            'audio_script_hash' => sha1('Test script.'),
            'status' => 'ready',
            'shots' => null,
        ]);

        $this->asset1 = SvgAsset::create([
            'user_id' => $this->teacher->id,
            'source' => 'commons',
            'source_ref' => 'test1',
            'source_url' => 'https://example.com/test1.svg',
            'title' => 'Test SVG 1',
            'license' => 'CC0',
            'svg_path' => 'assets/test1.svg',
        ]);

        $this->asset2 = SvgAsset::create([
            'user_id' => $this->teacher->id,
            'source' => 'commons',
            'source_ref' => 'test2',
            'source_url' => 'https://example.com/test2.svg',
            'title' => 'Test SVG 2',
            'license' => 'CC0',
            'svg_path' => 'assets/test2.svg',
        ]);
    }

    public function test_inspector_shows_scene_artwork_panel_after_attach(): void
    {
        // Regression: the selectedScene snapshot excludes shots, so the panel
        // must read the scene fresh — it stayed empty when it read the snapshot.
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id)
            ->assertSee('Layers')
            ->assertSee('Test SVG 1');
    }

    public function test_attach_appends_figure_layer_with_asset_id_to_every_existing_shot(): void
    {
        // Pre-populate shots with existing data
        $this->scene->update([
            'shots' => [
                ['order' => 0, 'image_path' => 'shot1.png', 'anchor_sentence' => 'First shot'],
                ['order' => 1, 'image_path' => 'shot2.png', 'anchor_sentence' => 'Second shot'],
            ],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id);

        $this->scene->refresh();
        $shots = $this->scene->shots;

        $this->assertNotNull($shots);
        $this->assertCount(2, $shots);

        // Each shot should have a cover layer + asset layer, preserving order
        $expectedPaths = ['shot1.png', 'shot2.png'];
        foreach ($shots as $index => $shot) {
            $layers = $shot['layers'] ?? [];
            $this->assertNotEmpty($layers, 'Shot should have layers after attach');
            $this->assertGreaterThanOrEqual(2, count($layers), 'Shot should have at least cover + asset layer');

            // First layer should be the cover layer (no asset_id) using the shot's own image_path
            $this->assertNull($layers[0]['asset_id'] ?? null);
            $this->assertSame('cover', $layers[0]['kind']);
            $this->assertSame($expectedPaths[$index], $layers[0]['path']);

            // Second layer should be the asset layer
            $this->assertSame($this->asset1->id, $layers[1]['asset_id']);
            $this->assertSame('figure', $layers[1]['kind']);
            $this->assertSame('assets/test1.svg', $layers[1]['path']);
        }
    }

    public function test_attach_on_scene_with_no_shots_but_image_path_creates_shots_with_cover_and_figure_layers(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id);

        $this->scene->refresh();
        $shots = $this->scene->shots;

        $this->assertNotNull($shots);
        $this->assertCount(1, $shots);

        $shot = $shots[0];
        $this->assertSame(0, $shot['order']);
        $this->assertSame('test-bg.png', $shot['image_path']);

        $layers = $shot['layers'] ?? [];
        $this->assertCount(2, $layers);

        // Cover layer
        $this->assertNull($layers[0]['asset_id'] ?? null);
        $this->assertSame('cover', $layers[0]['kind']);
        $this->assertSame(0.4, $layers[0]['depth']);

        // Asset layer
        $this->assertSame($this->asset1->id, $layers[1]['asset_id']);
        $this->assertSame('figure', $layers[1]['kind']);
        $this->assertSame(1.3, $layers[1]['depth']);
    }

    public function test_attach_on_scene_with_no_shots_and_no_image_path_does_not_create_shots(): void
    {
        $this->scene->update(['image_path' => null]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id);

        $this->scene->refresh();
        $this->assertNull($this->scene->shots);
    }

    public function test_attach_does_not_duplicate_the_same_asset(): void
    {
        $this->scene->update([
            'shots' => [
                ['order' => 0, 'image_path' => 'shot1.png', 'layers' => [
                    ['path' => 'shot1.png', 'kind' => 'cover', 'depth' => 0.4],
                    ['asset_id' => $this->asset1->id, 'path' => 'assets/test1.svg', 'kind' => 'figure', 'depth' => 1.3],
                ]],
            ],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id);

        $this->scene->refresh();
        $shot = $this->scene->shots[0];
        $layers = $shot['layers'];

        // Should still have only cover + asset1, not duplicated
        $this->assertCount(2, $layers);
        $assetLayers = array_filter($layers, fn ($l) => ($l['asset_id'] ?? null) === $this->asset1->id);
        $this->assertCount(1, $assetLayers, 'Asset should appear only once');
    }

    public function test_detach_removes_layer_from_all_shots_and_drops_layers_key_when_only_cover_remains(): void
    {
        $this->scene->update([
            'shots' => [
                [
                    'order' => 0, 'image_path' => 'shot1.png',
                    'layers' => [
                        ['path' => 'shot1.png', 'kind' => 'cover', 'depth' => 0.4],
                        ['asset_id' => $this->asset1->id, 'path' => 'assets/test1.svg', 'kind' => 'figure'],
                    ],
                ],
                [
                    'order' => 1, 'image_path' => 'shot2.png',
                    'layers' => [
                        ['path' => 'shot2.png', 'kind' => 'cover', 'depth' => 0.4],
                        ['asset_id' => $this->asset1->id, 'path' => 'assets/test1.svg', 'kind' => 'figure'],
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('detachArtwork', $this->asset1->id);

        $this->scene->refresh();
        $shots = $this->scene->shots;

        foreach ($shots as $shot) {
            // After detaching, layers key should not exist since only cover would remain
            $this->assertArrayNotHasKey('layers', $shot);
        }
    }

    public function test_update_artwork_layer_clamps_depth_to_3(): void
    {
        $this->scene->update([
            'shots' => [
                [
                    'order' => 0, 'image_path' => 'shot1.png',
                    'layers' => [
                        ['path' => 'shot1.png', 'kind' => 'cover', 'depth' => 0.4],
                        ['asset_id' => $this->asset1->id, 'path' => 'assets/test1.svg', 'kind' => 'figure', 'depth' => 1.0],
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('updateArtworkLayer', $this->asset1->id, 'depth', 99.5);

        $this->scene->refresh();
        $shot = $this->scene->shots[0];
        $layer = collect($shot['layers'])->firstWhere('asset_id', $this->asset1->id);

        // JSON storage drops float-ness on whole numbers (3.0 → 3); the payload
        // serializers cast back to float, so assert the value, not the PHP type.
        $this->assertSame(3.0, (float) $layer['depth']);
    }

    public function test_update_artwork_layer_rejects_unknown_field_silently(): void
    {
        $this->scene->update([
            'shots' => [
                [
                    'order' => 0, 'image_path' => 'shot1.png',
                    'layers' => [
                        ['path' => 'shot1.png', 'kind' => 'cover', 'depth' => 0.4],
                        ['asset_id' => $this->asset1->id, 'path' => 'assets/test1.svg', 'kind' => 'figure', 'depth' => 1.0],
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('updateArtworkLayer', $this->asset1->id, 'unknown_field', 'invalid_value');

        $this->scene->refresh();
        $shot = $this->scene->shots[0];
        $layer = collect($shot['layers'])->firstWhere('asset_id', $this->asset1->id);

        // Depth should remain unchanged (1.0); cast because JSON stores 1.0 as 1.
        $this->assertSame(1.0, (float) $layer['depth']);
    }

    public function test_attach_fails_when_asset_belongs_to_another_teacher(): void
    {
        $otherAsset = SvgAsset::create([
            'user_id' => $this->otherTeacher->id,
            'source' => 'commons',
            'source_ref' => 'other',
            'source_url' => 'https://example.com/other.svg',
            'title' => 'Other Teacher SVG',
            'license' => 'CC0',
            'svg_path' => 'assets/other.svg',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $otherAsset->id);
    }

    public function test_swapping_the_background_url_preserves_attached_clipart_layers(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Http::fake([
            'https://example.com/new-bg.jpg' => \Illuminate\Support\Facades\Http::response(
                'fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        // Attach clipart, then swap the background via a pasted URL.
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id)
            ->call('applyImageUrl', $this->scene->id, 'https://example.com/new-bg.jpg');

        $shots = $this->scene->fresh()->shots;

        // The clipart layer must survive the background swap — not be nulled out.
        $this->assertNotNull($shots, 'background swap must not delete attached clipart');
        $assetIds = collect($shots[0]['layers'] ?? [])->pluck('asset_id')->filter()->all();
        $this->assertContains($this->asset1->id, $assetIds, 'clipart asset layer should be preserved');
        // The new background is carried as the cover layer (no asset_id).
        $this->assertArrayNotHasKey('asset_id', $shots[0]['layers'][0], 'first layer is the cover');
        $this->assertSame('cover', $shots[0]['layers'][0]['kind'] ?? null);
    }

    public function test_painting_selection_replaces_a_generated_background_and_closes_the_picker(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://paintings.example/*' => Http::response('painting-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->app->instance(CommonsImageService::class, new class extends CommonsImageService
        {
            public function fileMeta(string $fileTitle): ?array
            {
                return [
                    'file_title' => 'Caesar painting.jpg',
                    'title' => 'Caesar Painting',
                    'artist' => 'Test Artist',
                    'license' => 'Public domain',
                    'image_url' => 'https://paintings.example/caesar.jpg',
                    'file_page' => 'https://commons.wikimedia.org/wiki/File:Caesar_painting.jpg',
                ];
            }
        });

        $this->scene->update([
            'image_path' => 'lessons/1/scenes/1/generated.webp',
            'skybox_image_path' => 'lessons/1/scenes/1/generated-skybox.png',
            'scene_view' => 'skybox',
            'status' => 'generating',
            'shots' => [
                ['order' => 0, 'image_path' => 'lessons/1/scenes/1/shots/one.webp'],
                ['order' => 1, 'image_path' => 'lessons/1/scenes/1/shots/two.webp'],
            ],
        ]);

        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('openPaintingPicker')
            ->assertSet('paintingPickerOpen', true)
            ->assertDispatched('painting-picker:open')
            ->call('applyPaintingBackground', 'commons', 'Caesar painting.jpg')
            ->assertSet('paintingPickerOpen', false);

        $scene = $this->scene->fresh();
        $this->assertNotSame('lessons/1/scenes/1/generated.webp', $scene->image_path);
        $this->assertNull($scene->shots);
        $this->assertNull($scene->skybox_image_path);
        $this->assertSame('slideshow', $scene->scene_view);
        $this->assertSame('ready', $scene->status);
        $this->assertSame('painting', $scene->config['background_source']);
        Storage::disk('public')->assertExists($scene->image_path);
        $this->assertFalse((bool) $component->get('paintingPickerOpen'));
    }
}
