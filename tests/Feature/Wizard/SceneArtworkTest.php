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

    public function test_attach_on_scene_with_no_shots_and_no_image_path_creates_a_layer_only_shot(): void
    {
        // INVERTED DELIBERATELY. This used to assert that nothing happened, which encoded a rule
        // Bart has since removed: "Should add icons as layers regardless if there's a background
        // image." A layer-only shot was already what a map scene got; every other scene was refused
        // for no reason a teacher could see, and a scene with nothing behind it still has
        // background_color for the layer to sit on.
        $this->scene->update(['image_path' => null]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id);

        $shots = $this->scene->refresh()->shots;
        $this->assertCount(1, $shots);
        $this->assertCount(1, $shots[0]['layers']);
        $this->assertArrayNotHasKey('image_path', $shots[0], 'layer-only: there is no cover to carry');
    }

    public function test_attach_on_imageless_voyage_scene_creates_a_layer_only_shot(): void
    {
        // A Route waypoint (voyage) scene has no flat background image — the map is the backdrop.
        // Clipart must still attach, as a shot carrying only the asset layer (no cover).
        $this->scene->update(['kind' => 'voyage', 'image_path' => null]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id);

        $this->scene->refresh();
        $shots = $this->scene->shots;

        $this->assertNotNull($shots);
        $this->assertCount(1, $shots);

        $layers = $shots[0]['layers'] ?? [];
        $this->assertCount(1, $layers, 'Only the asset layer — no cover for a map-backed scene');
        $this->assertSame($this->asset1->id, $layers[0]['asset_id']);
        $this->assertSame('figure', $layers[0]['kind']);
        $this->assertArrayNotHasKey('image_path', $shots[0]);
    }

    /**
     * A map block is a backdrop just as much as a voyage waypoint is. Only 'voyage' was
     * whitelisted, so putting a picture on a map answered "generate a scene background first" —
     * on a scene the teacher was looking at a full-screen map of.
     */
    public function test_attach_on_a_map_scene_creates_a_layer_only_shot(): void
    {
        $this->scene->update(['kind' => 'map', 'image_path' => null]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id);

        $layers = $this->scene->refresh()->shots[0]['layers'] ?? [];

        $this->assertCount(1, $layers, 'Only the asset layer — the map is the backdrop');
        $this->assertSame($this->asset1->id, $layers[0]['asset_id']);
    }

    /** A narration scene with nothing behind it still has to be told to make a background first. */
    public function test_attach_on_a_truly_empty_scene_is_accepted(): void
    {
        // Also inverted. The old name said "still refused" and the refusal was the bug: icons reach
        // attachArtwork() by a path that never checked, so the product accepted a camel on this
        // exact scene and refused a painting on it. Same action, to a teacher.
        $this->scene->update(['kind' => 'narration', 'image_path' => null]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id)
            ->assertNotDispatched('toast');

        $this->assertNotEmpty($this->scene->refresh()->shots[0]['layers'] ?? []);
    }

    /** The same rule has to hold for the Image tool, which downloads before it attaches. */
    public function test_a_painting_can_be_dropped_on_a_map_scene_as_a_layer(): void
    {
        Storage::fake('public');
        Http::fake(['https://paintings.example/*' => Http::response('painting-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
        $this->app->instance(CommonsImageService::class, new class extends CommonsImageService
        {
            public function fileMeta(string $fileTitle): ?array
            {
                return [
                    'file_title' => 'Map painting.jpg',
                    'title' => 'Map Painting',
                    'artist' => 'Test Artist',
                    'license' => 'Public domain',
                    'image_url' => 'https://paintings.example/map.jpg',
                    'file_page' => 'https://commons.wikimedia.org/wiki/File:Map_painting.jpg',
                ];
            }
        });

        $this->scene->update(['kind' => 'map', 'image_path' => null]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('openImageLibrary')
            ->call('applyPaintingAsLayer', 'commons', 'Map painting.jpg');

        $scene = $this->scene->refresh();
        $layers = $scene->shots[0]['layers'] ?? [];

        $this->assertCount(1, $layers, 'the painting lands as an editable layer, not a background');
        $this->assertNull($scene->image_path, 'the map stays the backdrop');
        $this->assertNotNull($layers[0]['asset_id'] ?? null, 'it needs an asset id to move / scale / delete');
    }

    /**
     * A teacher blowing one detail of a painting up to fill the stage is a normal thing to want.
     * The cap is a guard against a runaway drag, not a rendering limit.
     */
    public function test_a_layer_can_be_scaled_up_to_six(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id)
            ->call('updateArtworkLayer', $this->asset1->id, 'scale', 6);

        $layer = collect($this->scene->refresh()->shots[0]['layers'])->firstWhere('asset_id', $this->asset1->id);
        $this->assertSame(6.0, (float) $layer['scale']);
    }

    public function test_a_scale_beyond_the_cap_is_clamped_not_rejected(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id)
            ->call('updateArtworkLayer', $this->asset1->id, 'scale', 99);

        $layer = collect($this->scene->refresh()->shots[0]['layers'])->firstWhere('asset_id', $this->asset1->id);
        $this->assertSame(6.0, (float) $layer['scale']);
    }

    /** @return array<string, mixed> */
    private function attachedLayer(): array
    {
        return collect($this->scene->refresh()->shots[0]['layers'])
            ->firstWhere('asset_id', $this->asset1->id);
    }

    private function layerComponent()
    {
        return Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('attachArtwork', $this->asset1->id);
    }

    public function test_the_white_key_grayscale_and_tint_persist(): void
    {
        $this->layerComponent()
            ->call('updateArtworkLayer', $this->asset1->id, 'white_key', 0.04)
            ->call('updateArtworkLayer', $this->asset1->id, 'grayscale', true)
            ->call('updateArtworkLayer', $this->asset1->id, 'tint', '#38bdf8');

        $layer = $this->attachedLayer();
        $this->assertSame(0.04, (float) $layer['white_key']);
        $this->assertTrue((bool) $layer['grayscale']);
        $this->assertSame('#38bdf8', $layer['tint']);
    }

    /** The tint lands inside an SVG flood-color attribute, so anything but a hex colour is dropped. */
    public function test_a_tint_that_is_not_a_hex_colour_is_refused(): void
    {
        $this->layerComponent()
            ->call('updateArtworkLayer', $this->asset1->id, 'tint', '#38bdf8')
            ->call('updateArtworkLayer', $this->asset1->id, 'tint', 'red" onload="alert(1)');

        $this->assertSame('#38bdf8', $this->attachedLayer()['tint'], 'the good value must survive');
    }

    public function test_an_empty_tint_clears_it(): void
    {
        $this->layerComponent()
            ->call('updateArtworkLayer', $this->asset1->id, 'tint', '#38bdf8')
            ->call('updateArtworkLayer', $this->asset1->id, 'tint', '');

        $this->assertNull($this->attachedLayer()['tint']);
    }

    public function test_the_white_key_is_clamped_to_its_range(): void
    {
        $this->layerComponent()->call('updateArtworkLayer', $this->asset1->id, 'white_key', 5);

        $this->assertSame(0.5, (float) $this->attachedLayer()['white_key']);
    }

    /**
     * Format, Settings and Publish all act on THIS lesson. Help leaves it — a new tab, the help
     * centre — so it sits past Publish behind a divider rather than reading as a fourth thing you
     * do to the lesson.
     */
    public function test_help_sits_after_publish_in_the_toolbar(): void
    {
        $html = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->html();

        $format = strpos($html, 'aria-label="Format"');
        $settings = strpos($html, 'aria-label="Settings"');
        $help = strpos($html, 'aria-label="Help"');

        $this->assertNotFalse($format);
        $this->assertNotFalse($settings);
        $this->assertNotFalse($help);
        $this->assertLessThan($settings, $format, 'Format comes first');
        $this->assertLessThan($help, $settings, 'Help must not sit between Format and Settings');
    }

    public function test_detaching_the_last_clipart_from_a_voyage_scene_collapses_shots_to_null(): void
    {
        // A layer-only voyage shot carries nothing once its clipart is removed — it must not
        // linger as an empty shot (which would keep the scene "having shots" for no reason).
        $this->scene->update([
            'kind' => 'voyage',
            'image_path' => null,
            'shots' => [
                ['order' => 0, 'layers' => [
                    ['asset_id' => $this->asset1->id, 'path' => 'assets/test1.svg', 'kind' => 'figure', 'depth' => 1.3],
                ]],
            ],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('detachArtwork', $this->asset1->id);

        $this->assertSame([], $this->scene->refresh()->shots);
    }

    public function test_add_embed_layer_adds_a_3d_iframe_layer_to_a_voyage_scene(): void
    {
        $this->scene->update(['kind' => 'voyage', 'image_path' => null]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('addEmbedLayer', 'https://sketchfab.com/3d-models/eendracht-5122ceb52cfd4bf5be518fdf693efbb3', '3d');

        $layer = $this->scene->refresh()->shots[0]['layers'][0];
        $this->assertSame('embed', $layer['kind']);
        $this->assertSame('sketchfab', $layer['embed']['type']);
        $this->assertStringContainsString('5122ceb52cfd4bf5be518fdf693efbb3', $layer['embed']['src']);
        // A model arrives grabbable: the Interact toggle defaults on, and EmbedParser maps that to
        // Sketchfab's orbit controls. The chrome we don't want (badges, help, watermark, VR) is
        // still off — the assertion below is the one that guards that.
        $this->assertStringContainsString('ui_controls=1', $layer['embed']['src']);
        $this->assertStringContainsString('ui_infos=0', $layer['embed']['src']);
        $this->assertStringContainsString('ui_watermark=0', $layer['embed']['src']);
        $this->assertIsInt($layer['asset_id']);   // synthetic id → move/reorder/delete plumbing works
    }

    public function test_add_embed_layer_adds_a_muted_autoplay_video_layer(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)   // has image_path → cover + embed
            ->call('addEmbedLayer', 'https://www.youtube.com/watch?v=-E9T6UWaDRA', 'video');

        $layers = $this->scene->refresh()->shots[0]['layers'];
        $embed = collect($layers)->firstWhere('kind', 'embed')['embed'];
        $this->assertSame('video', $embed['type']);
        $this->assertStringContainsString('youtube.com/embed/-E9T6UWaDRA', $embed['src']);
        $this->assertStringContainsString('autoplay=1', $embed['src']);
        $this->assertStringContainsString('mute=1', $embed['src']);
        $this->assertStringContainsString('controls=0', $embed['src']);
    }

    public function test_add_embed_layer_rejects_a_non_embed_link(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('addEmbedLayer', 'not a link', '3d');

        // No layer added — shots stays null (the scene had none).
        $this->assertNull($this->scene->refresh()->shots);
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
