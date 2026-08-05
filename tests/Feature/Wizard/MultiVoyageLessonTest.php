<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A lesson may hold scenes from MORE THAN ONE voyage.
 *
 * Abel Tasman sailed twice, so lesson #41 carries scenes from tasman-1642 and tasman-1644. The
 * editor assumed one voyage per lesson: voyageDef() read only game_config['voyage'], which such a
 * lesson has no reason to set, so it returned null and every writer — setLegTitle, setLegWaypoint,
 * setLegDepart — took its early return and did nothing. No error, no toast. The map still drew
 * correctly, because the browser resolves each scene's route from the catalogue, so a teacher
 * renamed a stop, saw the map look right, and lost the edit on reload.
 *
 * The storage has to be per voyage as well. One `voyage_def` for the whole lesson cannot hold two
 * routes: leg 0 of 1642 and leg 0 of 1644 are different crossings, and voyage-tour.js lets `def`
 * win over the catalogue for EVERY scene — so a single shared copy would draw one voyage's route
 * under the other voyage's scenes.
 */
class MultiVoyageLessonTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Lesson, 2: Scene, 3: Scene} */
    private function twoVoyageLesson(): array
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Abel Tasman',
            'subject' => 'history',
            'grade_level' => '6',
            'game_type' => 'voyage',
            // Deliberately NO 'voyage' key: the lesson is not one voyage.
            'game_config' => ['voyage_map' => ['cities' => false]],
        ]);

        $first = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'voyage', 'status' => 'ready',
            'config' => ['voyage' => 'tasman-1642', 'leg' => 0],
        ]);
        $second = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 2, 'kind' => 'voyage', 'status' => 'ready',
            'config' => ['voyage' => 'columbus-1492', 'leg' => 0],
        ]);

        return [$teacher, $lesson, $first, $second];
    }

    public function test_renaming_a_stop_saves_on_a_lesson_that_holds_two_voyages(): void
    {
        [$teacher, $lesson, $first] = $this->twoVoyageLesson();

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $first->id)
            ->call('setLegTitle', 'Storm Bay');

        $this->assertSame(
            'Storm Bay',
            $lesson->refresh()->scenes()->whereKey($first->id)->value('location'),
            'the stop title was silently dropped',
        );
    }

    public function test_each_voyage_keeps_its_own_editable_copy(): void
    {
        [$teacher, $lesson, $first, $second] = $this->twoVoyageLesson();

        $c = Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);
        $c->call('selectScene', $first->id)->call('setLegTitle', 'Storm Bay');
        $c->call('selectScene', $second->id)->call('setLegTitle', 'Hispaniola');

        $defs = $lesson->refresh()->game_config['voyage_defs'] ?? [];

        $this->assertArrayHasKey('tasman-1642', $defs, 'the first voyage lost its copy');
        $this->assertArrayHasKey('columbus-1492', $defs, 'the second voyage lost its copy');
        $this->assertSame('Storm Bay', $defs['tasman-1642']['legs'][0]['title'] ?? null);
        $this->assertSame('Hispaniola', $defs['columbus-1492']['legs'][0]['title'] ?? null);
    }

    public function test_a_single_voyage_lesson_still_works_the_old_way(): void
    {
        // The overwhelmingly common shape, and the one every existing lesson on disk has.
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Columbus',
            'subject' => 'history',
            'grade_level' => '6',
            'game_type' => 'voyage',
            'game_config' => ['voyage' => 'columbus-1492'],
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'voyage', 'status' => 'ready',
            'config' => ['voyage' => 'columbus-1492', 'leg' => 1],
        ]);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('setLegTitle', 'San Salvador');

        $this->assertSame('San Salvador', $lesson->refresh()->scenes()->whereKey($scene->id)->value('location'));
    }
}
