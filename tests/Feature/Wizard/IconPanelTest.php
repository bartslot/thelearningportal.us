<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\IconPanel;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\SvgAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Icons tab of the bottom dock: two rows of pills over the bundled library, and placing an
 * icon on the selected scene as an ordinary layer — including, over a map, pinned to the place
 * the teacher dropped it on.
 *
 * NB: shots is a JSON column, so a whole float comes back as an int (50.0 → 50). Assertions on
 * round numbers cast before comparing; the fractional ones are left strict on purpose.
 */
class IconPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    private Scene $slideshowScene;

    private Scene $mapScene;

    private SvgAsset $pharaoh;

    private SvgAsset $arrow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Ancient Egypt', 'subject' => 'history', 'grade_level' => 'K-7',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);
        $this->slideshowScene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'ready',
            'image_path' => 'lessons/1/scenes/1/bg.jpg',
        ]);
        $this->mapScene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'map', 'status' => 'ready',
            'config' => ['qid' => 'Q79', 'year' => -1200],
        ]);

        $this->pharaoh = $this->bundled('line-art', 'Civilisations', 'Egyptians', 'Pharaoh');
        $this->bundled('line-art', 'Prehistoric', 'Dinosaurs', 'T rex');
        $this->arrow = $this->bundled('arrows', null, null, 'Arrow straight');
    }

    private function bundled(string $collection, ?string $category, ?string $subcategory, string $title): SvgAsset
    {
        return SvgAsset::create([
            'user_id' => null,
            'source' => 'bundled',
            'source_ref' => $collection.'/'.$title.'.svg',
            'collection' => $collection,
            'category' => $category,
            'subcategory' => $subcategory,
            'source_url' => '',
            'title' => $title,
            'license' => 'Royalty-free (commercial)',
            'svg_path' => 'svg-assets/library/'.$collection.'/'.str($title)->slug().'.svg',
        ]);
    }

    private function panel()
    {
        return Livewire::actingAs($this->teacher)->test(IconPanel::class);
    }

    private function wizardOn(Scene $scene)
    {
        return Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $scene->id);
    }

    // ── The two pill rows ──────────────────────────────────────────────────

    public function test_the_top_row_leads_with_line_art_not_alphabetically(): void
    {
        $this->assertSame(['line-art', 'arrows'], $this->panel()->instance()->collections());
    }

    public function test_the_second_row_lists_each_category_followed_by_its_subcategories(): void
    {
        $labels = array_column($this->panel()->instance()->groups(), 'label');

        $this->assertSame(['Civilisations', 'Egyptians', 'Prehistoric', 'Dinosaurs'], $labels);
    }

    public function test_a_flat_collection_has_no_second_row(): void
    {
        $this->assertSame([], $this->panel()->call('selectCollection', 'arrows')->instance()->groups());
    }

    public function test_picking_a_subcategory_narrows_the_grid_to_it(): void
    {
        $titles = $this->panel()
            ->call('selectGroup', 'Civilisations', 'Egyptians')
            ->instance()->icons()->pluck('title')->all();

        $this->assertSame(['Pharaoh'], $titles);
    }

    public function test_picking_a_whole_category_keeps_every_subcategory_in_it(): void
    {
        $titles = $this->panel()
            ->call('selectGroup', 'Prehistoric')
            ->instance()->icons()->pluck('title')->all();

        $this->assertSame(['T rex'], $titles);
    }

    public function test_switching_collection_clears_the_second_row_selection(): void
    {
        $panel = $this->panel()->call('selectGroup', 'Civilisations', 'Egyptians')->call('selectCollection', 'arrows');

        $panel->assertSet('category', '')->assertSet('subcategory', '');
    }

    public function test_an_unknown_collection_is_ignored(): void
    {
        $this->panel()->call('selectCollection', 'nonsense')->assertSet('collection', 'line-art');
    }

    public function test_the_panel_keeps_a_way_in_to_the_teachers_own_svg_library(): void
    {
        // The bundled sets are not the only icons a teacher can use — Import… still opens the
        // modal that pulls one in from Commons.
        $this->panel()->assertSeeHtml("Livewire.dispatch('open-svg-library')");
    }

    // ── Placing an icon ────────────────────────────────────────────────────

    public function test_picking_an_icon_asks_the_scene_to_place_it(): void
    {
        $this->panel()->call('place', $this->pharaoh->id)
            ->assertDispatched('svg-asset:attach', assetId: $this->pharaoh->id);
    }

    public function test_a_bundled_icon_becomes_a_layer_even_though_no_teacher_owns_it(): void
    {
        $this->wizardOn($this->slideshowScene)->call('attachArtwork', $this->pharaoh->id);

        $layers = $this->slideshowScene->fresh()->shots[0]['layers'];

        $this->assertSame($this->pharaoh->id, $layers[1]['asset_id']);
        $this->assertSame(50.0, (float) $layers[1]['x'], 'a click with no drop point lands mid-stage');
        $this->assertSame(58.0, (float) $layers[1]['y']);
        $this->assertArrayNotHasKey('anchor', $layers[1], 'no map under a slideshow scene, so nothing to pin to');
    }

    public function test_dropping_an_icon_places_it_where_it_landed(): void
    {
        $this->wizardOn($this->slideshowScene)->call('attachArtwork', $this->arrow->id, 22.5, 71.25);

        $layer = $this->slideshowScene->fresh()->shots[0]['layers'][1];

        $this->assertSame(22.5, $layer['x']);
        $this->assertSame(71.25, $layer['y']);
    }

    public function test_dropping_an_icon_on_a_map_pins_it_to_that_place(): void
    {
        $this->wizardOn($this->mapScene)->call('attachArtwork', $this->pharaoh->id, 40.0, 60.0, 31.2357, 30.0444);

        $layer = $this->mapScene->fresh()->shots[0]['layers'][0];

        $this->assertSame('map', $layer['anchor']);
        $this->assertSame(31.2357, $layer['lng']);
        $this->assertSame(30.0444, $layer['lat']);
    }

    public function test_a_place_off_the_globe_is_clamped_rather_than_stored(): void
    {
        $this->wizardOn($this->mapScene)->call('attachArtwork', $this->pharaoh->id, 40.0, 60.0, 999.0, -999.0);

        $layer = $this->mapScene->fresh()->shots[0]['layers'][0];

        $this->assertSame(180.0, (float) $layer['lng']);
        $this->assertSame(-90.0, (float) $layer['lat']);
    }

    // ── Dragging a pinned layer ────────────────────────────────────────────

    public function test_moving_a_pinned_layer_stores_the_new_place(): void
    {
        $this->wizardOn($this->mapScene)->call('attachArtwork', $this->pharaoh->id, 40.0, 60.0, 31.2357, 30.0444);

        $this->wizardOn($this->mapScene)
            ->call('moveArtworkLayer', $this->pharaoh->id, 55.0, 45.0, 1.5, 'map', 2.3522, 48.8566);

        $layer = $this->mapScene->fresh()->shots[0]['layers'][0];

        $this->assertSame(2.3522, $layer['lng']);
        $this->assertSame(48.8566, $layer['lat']);
        $this->assertSame(55.0, (float) $layer['x'], 'the screen position is kept too, as the pre-projection fallback');
    }

    public function test_unpinning_a_layer_drops_its_place(): void
    {
        $this->wizardOn($this->mapScene)->call('attachArtwork', $this->pharaoh->id, 40.0, 60.0, 31.2357, 30.0444);

        $this->wizardOn($this->mapScene)
            ->call('moveArtworkLayer', $this->pharaoh->id, 55.0, 45.0, 1.0, 'screen', null, null);

        $layer = $this->mapScene->fresh()->shots[0]['layers'][0];

        $this->assertArrayNotHasKey('anchor', $layer);
        $this->assertArrayNotHasKey('lng', $layer);
    }

    public function test_a_pin_without_coordinates_is_refused_rather_than_anchored_to_nowhere(): void
    {
        $this->wizardOn($this->mapScene)->call('attachArtwork', $this->pharaoh->id, 40.0, 60.0, 31.2357, 30.0444);

        // What a projector that failed to resolve the point would send.
        $this->wizardOn($this->mapScene)
            ->call('moveArtworkLayer', $this->pharaoh->id, 55.0, 45.0, 1.0, 'map', null, null);

        $layer = $this->mapScene->fresh()->shots[0]['layers'][0];

        $this->assertArrayNotHasKey('anchor', $layer);
    }

    public function test_an_older_caller_that_sends_no_anchor_leaves_the_pin_alone(): void
    {
        $this->wizardOn($this->mapScene)->call('attachArtwork', $this->pharaoh->id, 40.0, 60.0, 31.2357, 30.0444);

        $this->wizardOn($this->mapScene)->call('moveArtworkLayer', $this->pharaoh->id, 55.0, 45.0, 1.0);

        $layer = $this->mapScene->fresh()->shots[0]['layers'][0];

        $this->assertSame('map', $layer['anchor']);
        $this->assertSame(31.2357, $layer['lng']);
    }

    // ── Recolouring ────────────────────────────────────────────────────────

    public function test_a_tint_is_stored_as_a_hex_and_cleared_by_original(): void
    {
        $wizard = $this->wizardOn($this->slideshowScene);
        $wizard->call('attachArtwork', $this->pharaoh->id);

        $wizard->call('updateArtworkLayer', $this->pharaoh->id, 'tint', '#E11D48');
        $this->assertSame('#E11D48', $this->slideshowScene->fresh()->shots[0]['layers'][1]['tint']);

        $wizard->call('updateArtworkLayer', $this->pharaoh->id, 'tint', 'none');
        $this->assertSame('', $this->slideshowScene->fresh()->shots[0]['layers'][1]['tint']);
    }

    public function test_a_tint_that_is_not_a_colour_is_refused(): void
    {
        $wizard = $this->wizardOn($this->slideshowScene);
        $wizard->call('attachArtwork', $this->pharaoh->id);
        $wizard->call('updateArtworkLayer', $this->pharaoh->id, 'tint', 'javascript:alert(1)');

        $this->assertArrayNotHasKey('tint', $this->slideshowScene->fresh()->shots[0]['layers'][1]);
    }

    // ── Ownership ──────────────────────────────────────────────────────────

    public function test_another_teachers_import_is_still_refused(): void
    {
        $theirs = SvgAsset::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'commons', 'source_ref' => 'File:Theirs.svg', 'source_url' => 'https://example.test',
            'title' => 'Theirs', 'license' => 'CC0', 'svg_path' => 'svg-assets/9/theirs.svg',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->wizardOn($this->slideshowScene)->call('attachArtwork', $theirs->id);
    }

    public function test_the_layer_payload_carries_the_pin_and_the_tint_to_the_canvas(): void
    {
        $wizard = $this->wizardOn($this->mapScene);
        $wizard->call('attachArtwork', $this->pharaoh->id, 40.0, 60.0, 31.2357, 30.0444);
        $wizard->call('updateArtworkLayer', $this->pharaoh->id, 'tint', '#ffffff');

        $layer = $wizard->instance()->serializeShots($this->mapScene->fresh())[0]['layers'][0];

        $this->assertSame('map', $layer['anchor']);
        $this->assertSame(31.2357, $layer['lng']);
        $this->assertSame('#ffffff', $layer['tint']);
    }
}
