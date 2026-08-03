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
 * Phase-1 voyage CMS: the Configure step must treat voyage/gallery scenes as first-class
 * (not the generic "Slideshow" narration fallback), edit leg dates against a per-lesson
 * editable copy of the shared catalog, and manage stop/gallery images.
 */
class Step3VoyageTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    private Scene $voyageScene;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'De reis van Abel Tasman', 'subject' => 'history', 'grade_level' => 'K-7',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
            'game_type' => 'voyage', 'game_config' => ['voyage' => 'tasman-1642'],
        ]);
        // One scene per landfall: the gallery (description + images) lives inline on the voyage
        // scene at config.gallery, opened by the landfall hotspot in the player.
        $this->voyageScene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'voyage', 'status' => 'ready',
            'location' => 'Mauritius',
            'config' => [
                'voyage' => 'tasman-1642', 'leg' => 0, 'view' => 'flat', 'intro' => true, 'stop_images' => [],
                'gallery' => ['title' => 'Verversingspost', 'date_label' => '5 september 1642', 'story' => 'x', 'images' => []],
            ],
        ]);
    }

    private function wizardOn(Scene $scene)
    {
        return Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $scene->id);
    }

    public function test_voyage_scene_shows_the_voyage_inspector_with_gallery_not_slideshow(): void
    {
        $this->wizardOn($this->voyageScene)
            // The vocabulary is "Route": a scene on the route, with its stop on the Waypoint tab.
            // (Was "Voyage leg", then "Route waypoint" — "voyage" is gone from the scene UI.)
            ->assertSee('Waypoint')
            ->assertSee('Fleet')
            ->assertSee('Gallery / description')   // gallery is now merged into the voyage inspector
            ->assertDontSee('Slideshow');
    }

    public function test_selecting_a_voyage_scene_dispatches_scene_load_with_voyage_def(): void
    {
        $this->wizardOn($this->voyageScene)
            ->assertDispatched('scene:load', fn ($event, $params) => array_key_exists('voyage_def', $params['payload'])
                && ($params['payload']['voyage_def']['id'] ?? null) === 'tasman-1642');
    }

    public function test_set_voyage_view_persists_globally_and_coerces_garbage_to_flat(): void
    {
        // Projection is GLOBAL to the voyage now — it lives in game_config, not per-scene config.
        $this->wizardOn($this->voyageScene)->call('setVoyageView', 'globe');
        $this->assertSame('globe', $this->lesson->fresh()->game_config['voyage_view'], 'projection stored globally in game_config');
        // voyageView() reads the global, so it is shared by every voyage scene (not per-scene config).
        $this->assertSame('globe', $this->wizardOn($this->voyageScene)->instance()->voyageView());

        $this->wizardOn($this->voyageScene)->call('setVoyageView', 'nonsense');
        $this->assertSame('flat', $this->lesson->fresh()->game_config['voyage_view']);
    }

    public function test_set_leg_title_stores_stop_title_in_voyage_def_and_syncs_scene_location(): void
    {
        $c = $this->wizardOn($this->voyageScene);
        $leg = (int) $this->voyageScene->config['leg'];
        $c->call('setLegTitle', '  Hispaniola  ');

        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $this->assertSame('Hispaniola', $def['legs'][$leg]['title'], 'stop title lives in the voyage format (trimmed)');
        $this->assertSame('Hispaniola', $this->voyageScene->fresh()->location, 'landfall name mirrored to scene.location');
    }

    public function test_editing_a_leg_date_lazily_clones_the_catalog_voyage_into_the_lesson(): void
    {
        $this->assertArrayNotHasKey('voyage_def', $this->lesson->fresh()->game_config);

        $this->wizardOn($this->voyageScene)->call('setLegDepart', '1642-08-20');

        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $this->assertSame('1642-08-20', $def['legs'][0]['depart'], 'the edited date is stored on the lesson copy');
        // A FULL clone — waypoints + fleet must be copied so ship geometry stays consistent.
        $this->assertNotEmpty($def['waypoints'], 'waypoints cloned verbatim');
        $this->assertNotEmpty($def['fleet'], 'fleet cloned verbatim');
        $this->assertSame('tasman-1642', $def['id']);
    }

    public function test_leg_date_writer_rejects_non_iso_dates(): void
    {
        $this->wizardOn($this->voyageScene)->call('setLegArrive', '05-09-1642');
        // No clone / no write should have happened for a malformed date.
        $this->assertArrayNotHasKey('voyage_def', $this->lesson->fresh()->game_config);
    }

    public function test_stop_images_can_be_added_and_removed(): void
    {
        $this->wizardOn($this->voyageScene)->call('addStopImage', '/lessons/tasman/mauritius-voc.jpg');
        $this->assertSame(['/lessons/tasman/mauritius-voc.jpg'], $this->voyageScene->fresh()->config['stop_images']);

        $this->wizardOn($this->voyageScene)->call('removeStopImage', 0);
        $this->assertSame([], $this->voyageScene->fresh()->config['stop_images']);
    }

    public function test_uploading_a_landfall_image_stores_it_and_adds_it_to_stop_images(): void
    {
        // Force the local fallback path (no Cloudinary in the test env) so the assertion is
        // deterministic — the live Cloudinary upload + AVIF/WebP optimisation is verified separately.
        config(['services.cloudinary.url' => null]);
        Storage::fake('public');

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->voyageScene->id)
            ->call('openStopImagePicker')
            ->set('uploadImage', UploadedFile::fake()->image('landfall.jpg', 1200, 800));

        $stored = $this->voyageScene->fresh()->config['stop_images'];
        $this->assertCount(1, $stored);
        $this->assertStringStartsWith('/storage/lessons/'.$this->lesson->id, $stored[0]);
    }

    public function test_standalone_gallery_scene_images_route_to_config_images_not_config_gallery(): void
    {
        // A standalone story slide (intro/outro) keeps images at config.images — the shared gallery
        // mutators must target that path, not the voyage's config.gallery.images.
        $gallery = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'gallery', 'status' => 'ready',
            'config' => ['title' => 'La Navidad', 'story' => 'x', 'images' => []],
        ]);

        $c = $this->wizardOn($gallery);
        $c->call('addGalleryImage', 'https://cdn.example.com/a.jpg', 'Rijksmuseum')
            ->call('addGalleryImage', 'https://cdn.example.com/b.jpg', '');

        $cfg = $gallery->fresh()->config;
        $this->assertCount(2, $cfg['images'], 'images land at config.images');
        $this->assertArrayNotHasKey('images', $cfg['gallery'] ?? [], 'not at config.gallery.images');
        $this->assertSame(['url' => 'https://cdn.example.com/a.jpg', 'credit' => 'Rijksmuseum'], $cfg['images'][0]);

        // Reorder + remove work against the same path.
        $c->call('moveGalleryImage', 0, 1);
        $this->assertSame('https://cdn.example.com/b.jpg', $gallery->fresh()->config['images'][0]['url']);
        $c->call('removeGalleryImage', 0);
        $this->assertCount(1, $gallery->fresh()->config['images']);
    }

    public function test_gallery_fit_setting_routes_by_scene_kind(): void
    {
        $gallery = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'gallery', 'status' => 'ready',
            'config' => ['images' => []],
        ]);
        $this->wizardOn($gallery)->call('setGalleryFit', 'cover');
        $this->assertSame('cover', $gallery->fresh()->config['fit']);

        // Voyage landfall gallery stores fit under config.gallery.fit.
        $this->wizardOn($this->voyageScene)->call('setGalleryFit', 'cover');
        $this->assertSame('cover', $this->voyageScene->fresh()->config['gallery']['fit']);

        // Garbage coerces to 'fit'.
        $this->wizardOn($gallery)->call('setGalleryFit', 'nonsense');
        $this->assertSame('fit', $gallery->fresh()->config['fit']);
    }

    public function test_the_overview_scene_labels_the_departure_not_the_first_landfall(): void
    {
        // The opening overview scene names the port the voyage LEAVES from, and it shares leg 0 with
        // the first waypoint. Pinned like a landfall it landed on the first arrival coordinate, so
        // the start city's name was drawn across the first stop ("Venice" written over Israel).
        $overview = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 0, 'kind' => 'voyage', 'status' => 'ready',
            'location' => 'Batavia',
            'config' => ['voyage' => 'tasman-1642', 'leg' => 0, 'overview' => true],
        ]);

        $labels = $this->wizardOn($this->voyageScene)->instance()->voyageLegLabels();
        $byText = collect($labels)->keyBy('text');

        $this->assertSame('depart', $byText[$overview->location]['at'], 'overview names where the trip starts');
        $this->assertSame('arrive', $byText[$this->voyageScene->location]['at'], 'a waypoint names its landfall');
        $this->assertSame(0, $byText[$overview->location]['leg'], 'both still sit on leg 0 — only the end differs');
    }

    public function test_voyage_map_toggles_hide_layers_lesson_wide(): void
    {
        // Defaults show everything; toggling one option persists it on game_config.voyage_map.
        $c = $this->wizardOn($this->voyageScene);
        $c->call('setVoyageMap', 'cities', false)
            ->call('setVoyageMap', 'borders', false);

        $map = $this->lesson->fresh()->game_config['voyage_map'];
        $this->assertFalse($map['cities']);
        $this->assertFalse($map['borders']);
        $this->assertTrue($map['labels'] ?? true, 'untouched options keep their default');

        // Unknown keys are ignored (defensive).
        $c->call('setVoyageMap', 'evil', true);
        $this->assertArrayNotHasKey('evil', $this->lesson->fresh()->game_config['voyage_map']);
    }

    public function test_voyage_map_style_values_are_coerced_by_key(): void
    {
        $c = $this->wizardOn($this->voyageScene);

        // Valid hex colour + size/width/opacity are stored (clamped where relevant).
        $c->call('setVoyageMap', 'label_color', '#ff8800')
            ->call('setVoyageMap', 'label_size', 1.8)
            ->call('setVoyageMap', 'border_width', 2.5)
            ->call('setVoyageMap', 'border_opacity', 0.75);
        $map = $this->lesson->fresh()->game_config['voyage_map'];
        $this->assertSame('#ff8800', $map['label_color']);
        $this->assertSame(1.8, $map['label_size']);
        $this->assertSame(2.5, $map['border_width']);
        $this->assertSame(0.75, $map['border_opacity']);

        // Bad colour falls back to the default; out-of-range numbers clamp.
        $c->call('setVoyageMap', 'city_color', 'not-a-colour')
            ->call('setVoyageMap', 'border_opacity', 5)
            ->call('setVoyageMap', 'label_size', 99);
        $map = $this->lesson->fresh()->game_config['voyage_map'];
        $this->assertSame('#3a2c1a', $map['city_color']);    // default
        $this->assertEquals(1.0, $map['border_opacity']);    // clamped to max (JSON may narrow 1.0→1)
        $this->assertEquals(3.0, $map['label_size']);        // clamped to max
    }

    public function test_painted_fog_regions_are_stored_sanitised_and_clearable(): void
    {
        $c = $this->wizardOn($this->voyageScene);

        // A valid ring (5 pts) is stored under game_config.voyage_fog, rounded to 3 decimals.
        $c->call('addFogRegion', [[10.12345, -5.98765], [12.0, -5.0], [12.0, -8.0], [10.0, -8.0], [10.12345, -5.98765]]);
        $fog = $this->lesson->fresh()->game_config['voyage_fog'];
        $this->assertCount(1, $fog);
        $this->assertSame([10.123, -5.988], $fog[0][0]);

        // A degenerate ring (<4 usable points) is ignored.
        $c->call('addFogRegion', [[1, 1], [2, 2]]);
        $this->assertCount(1, $this->lesson->fresh()->game_config['voyage_fog']);

        // Out-of-range coords are dropped (this ring has <4 valid points → ignored).
        $c->call('addFogRegion', [[999, 999], [1000, 1000], [1, 1], [2, 2]]);
        $this->assertCount(1, $this->lesson->fresh()->game_config['voyage_fog']);

        // Clear wipes them all.
        $c->call('clearFog');
        $this->assertArrayNotHasKey('voyage_fog', $this->lesson->fresh()->game_config);
    }

    public function test_remove_fog_region_erases_by_index(): void
    {
        $c = $this->wizardOn($this->voyageScene);
        $ringA = [[10.0, -5.0], [12.0, -5.0], [12.0, -8.0], [10.0, -8.0], [10.0, -5.0]];
        $ringB = [[20.0, -5.0], [22.0, -5.0], [22.0, -8.0], [20.0, -8.0], [20.0, -5.0]];
        $ringC = [[30.0, -5.0], [32.0, -5.0], [32.0, -8.0], [30.0, -8.0], [30.0, -5.0]];
        $c->call('addFogRegion', $ringA);
        $c->call('addFogRegion', $ringB);
        $c->call('addFogRegion', $ringC);

        // Erase the middle one; A and C remain, re-indexed.
        $c->call('removeFogRegion', 1);
        $fog = $this->lesson->fresh()->game_config['voyage_fog'];
        $this->assertCount(2, $fog);
        $this->assertEquals([10.0, -5.0], $fog[0][0]);
        $this->assertEquals([30.0, -5.0], $fog[1][0]);

        // Out-of-range index is a harmless no-op.
        $c->call('removeFogRegion', 9);
        $this->assertCount(2, $this->lesson->fresh()->game_config['voyage_fog']);

        // Erasing the rest clears the key entirely.
        $c->call('removeFogRegion', 0);
        $c->call('removeFogRegion', 0);
        $this->assertArrayNotHasKey('voyage_fog', $this->lesson->fresh()->game_config);
    }

    public function test_undo_fog_pops_only_the_last_painted_region(): void
    {
        $c = $this->wizardOn($this->voyageScene);
        $ringA = [[10.0, -5.0], [12.0, -5.0], [12.0, -8.0], [10.0, -8.0], [10.0, -5.0]];
        $ringB = [[20.0, -5.0], [22.0, -5.0], [22.0, -8.0], [20.0, -8.0], [20.0, -5.0]];
        $c->call('addFogRegion', $ringA);
        $c->call('addFogRegion', $ringB);
        $this->assertCount(2, $this->lesson->fresh()->game_config['voyage_fog']);

        // Undo removes B, keeps A.
        $c->call('undoFog');
        $fog = $this->lesson->fresh()->game_config['voyage_fog'];
        $this->assertCount(1, $fog);
        $this->assertEquals([10.0, -5.0], $fog[0][0]);   // JSON round-trip may narrow 10.0 → 10

        // Undo the last one → key removed entirely; a further undo is a harmless no-op.
        $c->call('undoFog');
        $this->assertArrayNotHasKey('voyage_fog', $this->lesson->fresh()->game_config);
        $c->call('undoFog');
        $this->assertArrayNotHasKey('voyage_fog', $this->lesson->fresh()->game_config);
    }

    public function test_leg_labels_are_derived_from_voyage_scene_locations(): void
    {
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'voyage', 'status' => 'ready',
            'location' => 'Tonga',
            'config' => ['voyage' => 'tasman-1642', 'leg' => 3, 'view' => 'flat', 'stop_images' => [], 'gallery' => []],
        ]);

        $labels = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->instance()->voyageLegLabels();

        // Each waypoint scene names the landfall its leg arrives at ('arrive'); only the opening
        // overview scene names a departure — see the overview test above.
        $this->assertContains(['text' => 'Mauritius', 'leg' => 0, 'at' => 'arrive'], $labels);
        $this->assertContains(['text' => 'Tonga', 'leg' => 3, 'at' => 'arrive'], $labels);
    }

    public function test_gallery_text_can_be_edited_inline_via_the_gallery_text_changed_event(): void
    {
        $this->wizardOn($this->voyageScene)
            ->dispatch('galleryTextChanged', sceneId: $this->voyageScene->id, field: 'title', value: 'Mauritius stopover')
            ->dispatch('galleryTextChanged', sceneId: $this->voyageScene->id, field: 'story', value: 'A fresh water stop.');

        $gallery = $this->voyageScene->fresh()->config['gallery'];
        $this->assertSame('Mauritius stopover', $gallery['title']);
        $this->assertSame('A fresh water stop.', $gallery['story']);
    }

    public function test_gallery_text_edit_ignores_unknown_fields_and_mismatched_scene(): void
    {
        $this->wizardOn($this->voyageScene)
            ->dispatch('galleryTextChanged', sceneId: $this->voyageScene->id, field: 'evil', value: 'nope')
            ->dispatch('galleryTextChanged', sceneId: 99999, field: 'title', value: 'wrong scene');

        $gallery = $this->voyageScene->fresh()->config['gallery'];
        $this->assertArrayNotHasKey('evil', $gallery);
        $this->assertSame('Verversingspost', $gallery['title'], 'a mismatched sceneId must not overwrite the title');
    }

    public function test_adding_a_voyage_leg_appends_a_real_new_leg_with_endpoints(): void
    {
        $c = $this->wizardOn($this->voyageScene);
        // Force the catalog → lesson clone so we can count legs/waypoints before adding.
        $c->call('setLegDepart', '1642-08-14');
        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $legsBefore = count($def['legs']);
        $wpsBefore = count($def['waypoints']);

        $c->call('addScene', 'voyage');

        $gc = $this->lesson->fresh()->game_config;
        $this->assertCount($legsBefore + 1, $gc['voyage_def']['legs'], 'a NEW leg was appended');
        $this->assertCount($wpsBefore + 1, $gc['voyage_def']['waypoints'], 'a new landfall waypoint was added');

        $added = $this->lesson->scenes()->where('kind', 'voyage')->reorder()->orderByDesc('id')->first();
        $this->assertSame('ready', $added->status, 'voyage scenes need no generation');
        $this->assertSame($legsBefore, $added->config['leg'], 'the scene points at the newly appended leg');

        // The new leg sails FROM the previous landfall TO the new waypoint.
        $newLeg = $gc['voyage_def']['legs'][$legsBefore];
        $this->assertSame($wpsBefore, (int) $newLeg['wp'][1], 'to = the new waypoint');
    }

    public function test_adding_a_voyage_leg_drops_the_destination_at_the_given_map_centre(): void
    {
        $c = $this->wizardOn($this->voyageScene);
        $c->call('setLegDepart', '1642-08-14');   // clone the catalog first
        $c->call('addScene', 'voyage', null, -6.9, 37.2);   // e.g. the teacher panned to Palos

        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $added = $this->lesson->scenes()->where('kind', 'voyage')->reorder()->orderByDesc('id')->first();
        $toIdx = (int) $def['legs'][(int) $added->config['leg']]['wp'][1];
        $this->assertEquals([-6.9, 37.2], $def['waypoints'][$toIdx], 'new leg destination = the map centre');
    }

    public function test_duplicate_voyage_legs_are_split_into_unique_legs_on_mount(): void
    {
        // Legacy: a 2nd voyage scene sharing leg 0 with the fixture scene.
        $dupe = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'voyage', 'status' => 'ready',
            'config' => ['voyage' => 'tasman-1642', 'leg' => 0, 'view' => 'flat', 'intro' => false, 'stop_images' => [], 'gallery' => []],
        ]);

        // Mounting the wizard runs the repair.
        Livewire::actingAs($this->teacher)->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson]);

        $legs = $this->lesson->fresh()->scenes()->where('kind', 'voyage')->orderBy('order')->get()
            ->map(fn (Scene $s) => (int) $s->config['leg']);
        $this->assertSame($legs->count(), $legs->unique()->count(), 'each voyage scene owns a distinct leg');
        $this->assertSame(0, (int) $this->voyageScene->fresh()->config['leg'], 'the first scene keeps leg 0');
        $this->assertGreaterThan(0, (int) $dupe->fresh()->config['leg'], 'the duplicate got its own new leg');

        // The new leg sails FROM where leg 0 landed (continuous route).
        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $newLeg = $def['legs'][(int) $dupe->fresh()->config['leg']];
        $this->assertSame((int) $def['legs'][0]['wp'][1], (int) $newLeg['wp'][0], 'new leg departs the previous landfall');
    }

    public function test_add_leg_waypoint_inserts_a_bend_and_reindexes_later_legs(): void
    {
        $c = $this->wizardOn($this->voyageScene);   // leg 0
        $c->call('setLegDepart', '1642-08-14');     // clone the catalog
        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $wpsBefore = count($def['waypoints']);
        $leg0End = (int) $def['legs'][0]['wp'][1];
        $leg1Start = (int) $def['legs'][1]['wp'][0];

        $c->call('addLegWaypoint');

        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $this->assertCount($wpsBefore + 1, $def['waypoints'], 'a bend waypoint was inserted');
        $this->assertSame($leg0End + 1, (int) $def['legs'][0]['wp'][1], 'the current leg span grew by the bend');
        $this->assertSame($leg1Start + 1, (int) $def['legs'][1]['wp'][0], 'later legs were reindexed');
    }

    public function test_set_voyage_waypoint_moves_a_point_within_the_leg_only(): void
    {
        $c = $this->wizardOn($this->voyageScene);
        $c->call('setLegDepart', '1642-08-14');
        $destIdx = (int) $this->lesson->fresh()->game_config['voyage_def']['legs'][0]['wp'][1];

        $c->dispatch('voyageWaypointMoved', sceneId: $this->voyageScene->id, wpIndex: $destIdx, lng: 100.0, lat: -30.0);
        $this->assertEquals([100.0, -30.0], $this->lesson->fresh()->game_config['voyage_def']['waypoints'][$destIdx]);

        // A waypoint OUTSIDE this leg's span is rejected (can't be moved from here).
        $before = $this->lesson->fresh()->game_config['voyage_def']['waypoints'];
        $c->dispatch('voyageWaypointMoved', sceneId: $this->voyageScene->id, wpIndex: 999, lng: 0.0, lat: 0.0);
        $this->assertEquals($before, $this->lesson->fresh()->game_config['voyage_def']['waypoints']);
    }

    public function test_insert_voyage_waypoint_bends_the_line_at_an_arbitrary_point_within_the_leg(): void
    {
        $c = $this->wizardOn($this->voyageScene);   // leg 0
        $c->call('setLegDepart', '1642-08-14');     // clone the catalog
        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $wpsBefore = count($def['waypoints']);
        $start = (int) $def['legs'][0]['wp'][0];
        $end = (int) $def['legs'][0]['wp'][1];
        $leg1Start = (int) $def['legs'][1]['wp'][0];

        // Drag the line just after the leg's start → a bend lands at index start+1.
        $c->dispatch('voyageWaypointInserted', sceneId: $this->voyageScene->id, afterWpIndex: $start, lng: 55.0, lat: -20.0);

        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $this->assertCount($wpsBefore + 1, $def['waypoints'], 'a bend waypoint was inserted');
        $this->assertEquals([55.0, -20.0], $def['waypoints'][$start + 1], 'the bend sits right after the drop segment');
        $this->assertSame($end + 1, (int) $def['legs'][0]['wp'][1], 'the current leg span grew by the bend');
        $this->assertSame($leg1Start + 1, (int) $def['legs'][1]['wp'][0], 'later legs were reindexed');
    }

    public function test_insert_voyage_waypoint_rejects_a_point_outside_the_current_leg(): void
    {
        $c = $this->wizardOn($this->voyageScene);   // leg 0
        $c->call('setLegDepart', '1642-08-14');
        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $end = (int) $def['legs'][0]['wp'][1];
        $before = $def['waypoints'];

        // afterWpIndex == leg end is out of range (a bend must fall strictly inside the leg).
        $c->dispatch('voyageWaypointInserted', sceneId: $this->voyageScene->id, afterWpIndex: $end, lng: 10.0, lat: 10.0);
        $this->assertEquals($before, $this->lesson->fresh()->game_config['voyage_def']['waypoints'], 'nothing inserted');

        // A mismatched scene id is ignored too.
        $c->dispatch('voyageWaypointInserted', sceneId: 99999, afterWpIndex: 0, lng: 10.0, lat: 10.0);
        $this->assertEquals($before, $this->lesson->fresh()->game_config['voyage_def']['waypoints']);
    }

    public function test_set_leg_waypoint_moves_the_leg_endpoint(): void
    {
        $c = $this->wizardOn($this->voyageScene);
        $c->dispatch('voyageLegEndpointMoved', sceneId: $this->voyageScene->id, which: 'to', lng: 20.5, lat: -12.25);

        $def = $this->lesson->fresh()->game_config['voyage_def'];
        $leg = $def['legs'][(int) $this->voyageScene->config['leg']];
        $toIdx = (int) $leg['wp'][1];
        $this->assertEquals([20.5, -12.25], $def['waypoints'][$toIdx], 'the TO waypoint moved to the drop point');

        // A mismatched scene id is ignored.
        $before = $this->lesson->fresh()->game_config['voyage_def']['waypoints'];
        $c->dispatch('voyageLegEndpointMoved', sceneId: 99999, which: 'to', lng: 0, lat: 0);
        $this->assertEquals($before, $this->lesson->fresh()->game_config['voyage_def']['waypoints']);
    }

    public function test_adding_a_voyage_leg_is_rejected_on_a_non_voyage_lesson(): void
    {
        $plain = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Rome', 'subject' => 'history', 'grade_level' => 'K-7',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $plain])
            ->call('addScene', 'voyage');

        // 'voyage' is not allowed here, so it falls back to a narration scene — never a voyage one.
        $this->assertSame(0, $plain->scenes()->where('kind', 'voyage')->count());
    }

    public function test_gallery_images_live_under_config_gallery_and_can_be_reordered(): void
    {
        $this->wizardOn($this->voyageScene)
            ->call('addGalleryImage', '/lessons/tasman/a.jpg', 'Rijksmuseum')
            ->call('addGalleryImage', '/lessons/tasman/b.jpg', '');

        $images = $this->voyageScene->fresh()->config['gallery']['images'];
        $this->assertSame(['url' => '/lessons/tasman/a.jpg', 'credit' => 'Rijksmuseum'], $images[0]);
        $this->assertCount(2, $images);

        $this->wizardOn($this->voyageScene)->call('moveGalleryImage', 0, 1);
        $this->assertSame('/lessons/tasman/b.jpg', $this->voyageScene->fresh()->config['gallery']['images'][0]['url']);

        $this->wizardOn($this->voyageScene)->call('setGalleryImageCredit', 0, 'Nationaal Archief');
        $this->assertSame('Nationaal Archief', $this->voyageScene->fresh()->config['gallery']['images'][0]['credit']);
    }
}
