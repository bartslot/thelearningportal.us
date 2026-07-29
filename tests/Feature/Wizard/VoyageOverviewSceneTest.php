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
}
