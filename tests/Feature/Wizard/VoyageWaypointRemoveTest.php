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
 * Removing a bend by double-clicking it on the map.
 *
 * Bends could be added and dragged but never taken away, so a misplaced one had to be dragged out
 * of sight. Only INTERIOR points of the current leg may go — its two endpoints are landfalls that
 * other scenes sail to.
 */
class VoyageWaypointRemoveTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: \App\Models\Lesson, 2: \App\Models\Scene, 3: int} */
    private function legScene(): array
    {
        $teacher = User::factory()->create();
        $lesson = app(VoyageLessonBuilder::class)->build('columbus-1492', $teacher);
        $scene = $lesson->scenes()->where('kind', 'voyage')->ordered()->get()
            ->first(fn ($s) => empty($s->config['overview']));
        $this->assertNotNull($scene);

        return [$teacher, $lesson, $scene, (int) ($scene->config['leg'] ?? 0)];
    }

    private function waypoints($lesson): array
    {
        return $lesson->fresh()->game_config['voyage_def']['waypoints'] ?? [];
    }

    private function legSpan($lesson, int $leg): array
    {
        $wp = $lesson->fresh()->game_config['voyage_def']['legs'][$leg]['wp'] ?? [0, 1];

        return [(int) $wp[0], (int) $wp[1]];
    }

    public function test_a_bend_added_to_the_leg_can_be_removed_again(): void
    {
        [$teacher, $lesson, $scene, $leg] = $this->legScene();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        [$start] = $this->legSpan($lesson, $leg);
        $component->call('insertVoyageWaypoint', $scene->id, $start, -30.0, 35.0);

        $withBend = $this->waypoints($lesson);
        $this->assertEqualsWithDelta(-30.0, (float) $withBend[$start + 1][0], 0.001, 'the bend should sit right after the leg start');
        $this->assertEqualsWithDelta(35.0, (float) $withBend[$start + 1][1], 0.001);

        $component->call('removeVoyageWaypoint', $scene->id, $start + 1);

        $after = $this->waypoints($lesson);
        $this->assertCount(count($withBend) - 1, $after);
        $this->assertNotEqualsWithDelta(-30.0, (float) ($after[$start + 1][0] ?? 0), 0.001);
    }

    public function test_removing_a_bend_shifts_later_legs_back(): void
    {
        [$teacher, $lesson, $scene, $leg] = $this->legScene();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        // Materialise the editable route first, so this compares two real span sets.
        [$start, $end] = $this->legSpan($lesson, $leg);
        $component->call('setVoyageWaypoint', $scene->id, $end, -9.0, 38.0);
        $spansBefore = $lesson->fresh()->game_config['voyage_def']['legs'] ?? [];
        $this->assertNotEmpty($spansBefore);

        $component->call('insertVoyageWaypoint', $scene->id, $start, -30.0, 35.0);
        $component->call('removeVoyageWaypoint', $scene->id, $start + 1);

        $this->assertSame(
            array_column($spansBefore, 'wp'),
            array_column($lesson->fresh()->game_config['voyage_def']['legs'] ?? [], 'wp'),
            'add + remove should leave every leg pointing at the same waypoints as before',
        );
    }

    public function test_the_legs_own_landfalls_cannot_be_removed(): void
    {
        [$teacher, $lesson, $scene, $leg] = $this->legScene();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        // Materialise an editable copy first, so this asserts the guard and not a missing route.
        [$start, $end] = $this->legSpan($lesson, $leg);
        $component->call('setVoyageWaypoint', $scene->id, $end, -9.0, 38.0);
        $before = $this->waypoints($lesson);

        $component->call('removeVoyageWaypoint', $scene->id, $start);
        $component->call('removeVoyageWaypoint', $scene->id, $end);

        $this->assertSame($before, $this->waypoints($lesson));
    }

    public function test_removing_a_bend_can_be_undone(): void
    {
        [$teacher, $lesson, $scene, $leg] = $this->legScene();

        $component = Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);

        [$start] = $this->legSpan($lesson, $leg);
        $component->call('insertVoyageWaypoint', $scene->id, $start, -30.0, 35.0);
        $withBend = $this->waypoints($lesson);

        $component->call('removeVoyageWaypoint', $scene->id, $start + 1);
        $component->call('undoVoyage');

        $this->assertSame($withBend, $this->waypoints($lesson));
    }
}
