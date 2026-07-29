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
 * Undo for route edits.
 *
 * Fog painting had Cmd-Z; dragging a waypoint or bending the sailing line did not, so one slip
 * rewrote the route with no way back. These cover the history that fixes it.
 */
class VoyageRouteUndoTest extends TestCase
{
    use RefreshDatabase;

    private function voyageLesson(): array
    {
        $teacher = User::factory()->create();
        $lesson = app(VoyageLessonBuilder::class)->build('columbus-1492', $teacher);
        // Skip the overview/itinerary scene — it sails no leg, so there is nothing to drag on it.
        $scene = $lesson->scenes()->where('kind', 'voyage')->ordered()->get()
            ->first(fn ($s) => empty($s->config['overview']));
        $this->assertNotNull($scene, 'the builder should produce at least one leg scene');

        return [$teacher, $lesson, $scene];
    }

    private function waypoints($lesson): array
    {
        return $lesson->fresh()->game_config['voyage_def']['waypoints'] ?? [];
    }

    public function test_dragging_a_waypoint_can_be_undone(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        // Whatever the route looks like when the teacher arrives — catalogue-backed or already
        // edited — a drag must be reversible back to exactly that. Asserting the round trip rather
        // than a particular starting state keeps this honest as the builder evolves.
        $before = $this->waypoints($lesson);

        $leg = $scene->config['leg'] ?? 0;
        // The leg's own end waypoint, read from the catalogue route the scene is showing.
        $wpIndex = (int) ($scene->config['leg'] !== null ? ($leg + 1) : 1);

        $component->call('setVoyageWaypoint', $scene->id, $wpIndex, -12.5, 41.0);
        $after = $this->waypoints($lesson);
        $this->assertNotEmpty($after, 'the drag should have materialised an edited route');

        $component->call('undoVoyage');
        $this->assertSame($before, $this->waypoints($lesson), 'undo should return to the route as found');
    }

    public function test_undo_walks_back_several_drags_in_order(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        $leg = $scene->config['leg'] ?? 0;
        $wpIndex = (int) ($lesson->fresh()->game_config['voyage_def']['legs'][$leg]['wp'][1] ?? 1);

        $states = [$this->waypoints($lesson)];
        foreach ([[-10.0, 40.0], [-11.0, 39.0], [-12.0, 38.0]] as [$lng, $lat]) {
            $component->call('setVoyageWaypoint', $scene->id, $wpIndex, $lng, $lat);
            $states[] = $this->waypoints($lesson);
        }

        // Peeling the edits back should retrace the same states in reverse.
        for ($i = count($states) - 2; $i >= 0; $i--) {
            $component->call('undoVoyage');
            $this->assertSame($states[$i], $this->waypoints($lesson), "undo step {$i}");
        }
    }

    public function test_undo_is_a_no_op_when_there_is_nothing_to_take_back(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        $before = $this->waypoints($lesson);
        $component->call('undoVoyage')->call('undoVoyage');

        $this->assertSame($before, $this->waypoints($lesson));
        $this->assertFalse($component->instance()->canUndoVoyage);
    }

    /** A reset discards every edit at once, so it has to be reversible too. */
    public function test_resetting_the_route_can_be_undone(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        $leg = $scene->config['leg'] ?? 0;
        $wpIndex = (int) ($lesson->fresh()->game_config['voyage_def']['legs'][$leg]['wp'][1] ?? 1);
        $component->call('setVoyageWaypoint', $scene->id, $wpIndex, -9.9, 37.7);
        $edited = $this->waypoints($lesson);

        $component->call('resetVoyageRoute');
        $this->assertArrayNotHasKey('voyage_def', $lesson->fresh()->game_config ?? []);

        $component->call('undoVoyage');
        $this->assertSame($edited, $this->waypoints($lesson), 'the reset should be reversible');
    }

    /** The history is bounded so game_config cannot grow without limit. */
    public function test_history_is_capped(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        $leg = $scene->config['leg'] ?? 0;
        $wpIndex = (int) ($lesson->fresh()->game_config['voyage_def']['legs'][$leg]['wp'][1] ?? 1);

        for ($i = 0; $i < 40; $i++) {
            $component->call('setVoyageWaypoint', $scene->id, $wpIndex, -20 + ($i * 0.1), 40.0);
        }

        $this->assertLessThanOrEqual(25, count($lesson->fresh()->game_config['voyage_undo'] ?? []));
    }
}
