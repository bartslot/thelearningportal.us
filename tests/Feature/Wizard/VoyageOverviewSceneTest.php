<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\User;
use App\Services\VoyageLessonBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The itinerary scene: the whole voyage on one screen, before anyone sets off.
 *
 * It must carry NO geometry of its own — the renderer derives the route and every stop from
 * voyage_def — so adding, moving or deleting a leg keeps it correct for free.
 */
class VoyageOverviewSceneTest extends TestCase
{
    use RefreshDatabase;

    private function build(string $voyage = 'columbus-1492')
    {
        return app(VoyageLessonBuilder::class)->build($voyage, User::factory()->create());
    }

    /** The wizard, driven as the lesson's own teacher (it does not mount for a guest). */
    private function wizard($lesson)
    {
        return Livewire::actingAs($lesson->teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);
    }

    public function test_the_first_voyage_scene_is_the_overview(): void
    {
        $lesson = $this->build();
        $voyageScenes = $lesson->scenes()->where('kind', 'voyage')->ordered()->get();

        $first = $voyageScenes->first();
        $this->assertTrue((bool) ($first->config['overview'] ?? false), 'the itinerary should come first');
        $this->assertSame(1, $voyageScenes->where('config.overview', true)->count(), 'exactly one overview');
    }

    public function test_the_overview_stores_no_route_of_its_own(): void
    {
        $lesson = $this->build();
        $overview = $lesson->scenes()->where('kind', 'voyage')->ordered()->first();

        // Anything cached here would go stale the moment a leg moved.
        foreach (['waypoints', 'legs', 'stops', 'bounds'] as $key) {
            $this->assertArrayNotHasKey($key, $overview->config, "overview must not cache {$key}");
        }
        $this->assertSame('columbus-1492', $overview->config['voyage']);
    }

    public function test_every_leg_still_gets_its_own_scene(): void
    {
        $lesson = $this->build();
        $legScenes = $lesson->scenes()->where('kind', 'voyage')->ordered()->get()
            ->filter(fn ($s) => empty($s->config['overview']));

        $catalog = json_decode(file_get_contents(resource_path('js/timemap/voyages.json')), true);
        $entry = collect($catalog['voyages'])->firstWhere('id', 'columbus-1492');
        $catalogLegs = count($entry['legs']);
        $this->assertSame($catalogLegs, $legScenes->count());
        // Leg indexes stay 0..n-1 and in order — the overview must not shift them.
        $this->assertSame(range(0, $catalogLegs - 1), $legScenes->pluck('config.leg')->map(fn ($v) => (int) $v)->values()->all());
    }

    /**
     * Opening the wizard must not renumber the legs.
     *
     * The wizard repairs lessons where two scenes claim the same leg. The itinerary stores leg 0
     * as a seed for the map's era and style while sailing nothing, so it looked like a second
     * claim on leg 0 — and the repair moved the FIRST leg's scene to the end of the voyage.
     */
    public function test_opening_the_wizard_leaves_every_scene_on_its_own_leg(): void
    {
        $teacher = User::factory()->create();
        $lesson = app(VoyageLessonBuilder::class)->build('columbus-1492', $teacher);
        $before = $lesson->scenes()->where('kind', 'voyage')->ordered()->pluck('config')
            ->map(fn ($c) => (int) ($c['leg'] ?? 0))->all();

        Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);

        $after = $lesson->fresh()->scenes()->where('kind', 'voyage')->ordered()->pluck('config')
            ->map(fn ($c) => (int) ($c['leg'] ?? 0))->all();
        $this->assertSame($before, $after);
    }

    public function test_the_overview_is_ready_so_it_never_blocks_publishing(): void
    {
        $lesson = $this->build();
        $overview = $lesson->scenes()->where('kind', 'voyage')->ordered()->first();
        $this->assertSame('ready', $overview->status);
    }

    /**
     * The Single ⇄ Overview switch writes to the SCENE.
     *
     * Held in the panel it looked right until the teacher clicked another scene and came back to
     * find it on Single again — and a student never saw the itinerary at all, since this same flag
     * is what makes the player open the overview instead of sailing a leg.
     */
    public function test_a_waypoint_scene_can_be_switched_to_the_overview_and_back(): void
    {
        $teacher = User::factory()->create();
        $lesson = app(VoyageLessonBuilder::class)->build('columbus-1492', $teacher);
        $scene = $lesson->scenes()->where('kind', 'voyage')->ordered()->get()
            ->first(fn ($s) => empty($s->config['overview']));

        Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('setVoyageOverview', true);
        $this->assertTrue((bool) $scene->fresh()->config['overview']);

        Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('setVoyageOverview', false);
        $this->assertFalse((bool) $scene->fresh()->config['overview']);
    }

    /** Flipping to the overview and back must land on the waypoint it came from. */
    public function test_switching_keeps_the_scenes_own_leg(): void
    {
        $teacher = User::factory()->create();
        $lesson = app(VoyageLessonBuilder::class)->build('columbus-1492', $teacher);
        $scene = $lesson->scenes()->where('kind', 'voyage')->ordered()->get()
            ->first(fn ($s) => empty($s->config['overview']));
        $leg = (int) $scene->config['leg'];

        Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('setVoyageOverview', true)
            ->call('setVoyageOverview', false);

        $this->assertSame($leg, (int) $scene->fresh()->config['leg']);
    }

    /**
     * The itinerary the teacher reads in Format → Route: every place the voyage calls at, numbered
     * in sailing order, with stop 1 the port it leaves from.
     */
    public function test_the_itinerary_lists_every_stop_in_sailing_order(): void
    {
        $lesson = $this->build();

        $stops = $this->wizard($lesson)
            ->instance()->voyageStops();

        $legCount = count($this->wizard($lesson)->instance()->voyageDef()['legs'] ?? []);
        // Only scenes ON the route: the overview shows them all, it is not one of them.
        $this->assertCount($legCount, $stops, 'one row per leg, and nothing else');
        $this->assertNotContains(null, array_column($stops, 'leg'), 'every row is a leg');
        // Stop 1 is the first place the voyage ARRIVES at; the port it left from is not a stop.
        $this->assertSame(1, $stops[0]['n']);
        $this->assertSame(range(1, $legCount), array_column($stops, 'n'));
    }

    /**
     * A voyage that comes home calls at N places, not N+1: the last leg ends where the first began,
     * so it wears stop 1's number again instead of inventing a new one. The map's pins number it
     * the same way (voyage-tour stopNumbers) — a class counting stops must get one answer.
     */
    public function test_a_round_trip_returns_to_stop_one_instead_of_a_new_number(): void
    {
        $lesson = $this->build('tasman-1642');
        $def = $this->wizard($lesson)->instance()->voyageDef();
        $points = $def['waypoints'];
        $legs = $def['legs'];
        $start = $points[$legs[0]['wp'][0]];
        $end = $points[$legs[count($legs) - 1]['wp'][1]];
        if (abs($start[0] - $end[0]) >= 1.0 || abs($start[1] - $end[1]) >= 1.0) {
            $this->markTestSkipped('this catalogue route is not a round trip');
        }

        $stops = $this->wizard($lesson)
            ->instance()->voyageStops();

        // Coming home adds no new place to count: the numbers still run 1..N over the landfalls,
        // and the homecoming is simply the last of them.
        $this->assertSame(range(1, count($stops)), array_column($stops, 'n'));
    }

    /** Reordering the itinerary re-sails the route: the legs run in the new order, still chained. */
    public function test_reordering_stops_rechains_the_route(): void
    {
        $lesson = $this->build();
        $before = $this->wizard($lesson)->instance()->voyageDef();
        $legCount = count($before['legs'] ?? []);
        if ($legCount < 3) {
            $this->markTestSkipped('needs at least three legs to reorder meaningfully');
        }

        $order = range(0, $legCount - 1);
        [$order[0], $order[1]] = [$order[1], $order[0]];   // swap the first two crossings

        $this->wizard($lesson)
            ->call('reorderVoyageStops', $order);

        $after = $lesson->fresh()->game_config['voyage_def'];
        $this->assertCount($legCount, $after['legs']);
        // Still one continuous chain: every leg starts where the previous one ended.
        foreach ($after['legs'] as $i => $leg) {
            if ($i === 0) {
                $this->assertSame(0, $leg['wp'][0], 'the first leg departs the origin');

                continue;
            }
            $this->assertSame($after['legs'][$i - 1]['wp'][1], $leg['wp'][0], "leg {$i} sails from the previous landfall");
        }
        // The swapped crossings kept their own dates — reordering moves a leg, it does not rewrite it.
        $this->assertSame($before['legs'][1]['depart'], $after['legs'][0]['depart']);
    }

    /** A leg with no scene is a stop nobody can watch; the itinerary offers to make one. */
    public function test_deleting_a_stop_drops_its_leg_and_rechains_the_route(): void
    {
        $lesson = $this->build();
        $wizard = $this->wizard($lesson);
        $before = $wizard->instance()->voyageDef();
        $legCount = count($before['legs']);
        $doomed = $legCount - 1;
        $endOfKept = $before['waypoints'][$before['legs'][$doomed - 1]['wp'][1]];

        $wizard->call('deleteVoyageStop', $doomed);

        $after = $this->wizard($lesson->fresh())->instance()->voyageDef();
        $this->assertCount($legCount - 1, $after['legs'], 'the crossing is gone');
        // The voyage now ends where the leg before it ended — the route is re-chained, not truncated
        // mid-air, and every remaining leg still points at real waypoints.
        $last = $after['legs'][count($after['legs']) - 1];
        $this->assertSame($endOfKept, $after['waypoints'][$last['wp'][1]]);
        foreach ($after['legs'] as $leg) {
            $this->assertArrayHasKey($leg['wp'][0], $after['waypoints']);
            $this->assertArrayHasKey($leg['wp'][1], $after['waypoints']);
        }
    }

    public function test_the_last_remaining_stop_cannot_be_deleted(): void
    {
        $lesson = $this->build();
        $wizard = $this->wizard($lesson);
        $def = $wizard->instance()->voyageDef();
        for ($i = count($def['legs']) - 1; $i > 0; $i--) {
            $wizard->call('deleteVoyageStop', $i);
        }

        $wizard->call('deleteVoyageStop', 0);

        // A voyage with no crossing is not a voyage — the last one stays put.
        $this->assertCount(1, $this->wizard($lesson->fresh())->instance()->voyageDef()['legs']);
    }

    public function test_a_leg_without_a_scene_can_be_given_one(): void
    {
        $lesson = $this->build();
        $orphan = count($this->wizard($lesson)
            ->instance()->voyageDef()['legs']) - 1;
        $lesson->scenes()->where('kind', 'voyage')
            ->get()
            ->first(fn ($s) => ! ($s->config['overview'] ?? false) && (int) ($s->config['leg'] ?? 0) === $orphan)
            ?->delete();

        $component = $this->wizard($lesson);
        $missing = collect($component->instance()->voyageStops())->firstWhere('leg', $orphan);
        $this->assertNull($missing['scene_id'], 'the stop starts with no scene');

        $component->call('addVoyageSceneForLeg', $orphan);

        $stops = $this->wizard($lesson->fresh())
            ->instance()->voyageStops();
        $this->assertNotNull(collect($stops)->firstWhere('leg', $orphan)['scene_id']);
    }
}
