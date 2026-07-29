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
 * Choosing how a leg was travelled.
 *
 * Transport belongs to the CROSSING, not to the stop at either end, so it is edited per leg and
 * the marker swaps at the landfall where the traveller comes ashore. A leg may instead carry a
 * picture, for the journeys no model covers.
 */
class VoyageTransportPickerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:\App\Models\Lesson,2:\App\Models\Scene} */
    private function voyageLesson(): array
    {
        $teacher = User::factory()->create();
        $lesson = app(VoyageLessonBuilder::class)->build('marco-polo-1271', $teacher);
        // Skip the itinerary scene — it sails no leg, so it has nothing to travel by.
        $scene = $lesson->scenes()->where('kind', 'voyage')->ordered()->get()
            ->first(fn ($s) => empty($s->config['overview']));
        $this->assertNotNull($scene, 'the builder should produce at least one leg scene');

        return [$teacher, $lesson, $scene];
    }

    /** The leg this scene actually sails, read fresh — mounting the wizard can renumber legs. */
    private function leg($lesson, $scene): array
    {
        $index = (int) ($scene->fresh()->config['leg'] ?? 0);

        return $lesson->fresh()->game_config['voyage_def']['legs'][$index] ?? [];
    }

    private function inspector(User $teacher, $lesson, $scene)
    {
        return Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id);
    }

    public function test_the_catalogue_offers_both_collections_with_a_thumbnail_each(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();
        $transports = $this->inspector($teacher, $lesson, $scene)->instance()->transports();

        $this->assertNotEmpty($transports);
        $terrains = array_unique(array_column($transports, 'terrain'));
        sort($terrains);
        $this->assertSame(['land', 'water'], $terrains, 'the picker needs a sea and a land collection');

        foreach ($transports as $t) {
            $this->assertNotSame('', $t['label'], "{$t['id']} needs a label");
            $this->assertFileExists(public_path(ltrim($t['thumb'], '/')), "{$t['id']} has no rendered thumbnail");
        }
    }

    public function test_picking_a_transport_writes_it_to_that_leg_alone(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();
        $component = $this->inspector($teacher, $lesson, $scene);

        // The route as the teacher finds it — the lesson's own copy if it has one, else the
        // catalogue it is about to be cloned from.
        $before = $component->instance()->voyageDef()['legs'] ?? [];
        $component->call('setLegTransport', 'camel');

        $this->assertSame('camel', $this->leg($lesson, $scene)['transport']);

        // Every OTHER leg is untouched: this is a per-leg setting, not a voyage-wide one.
        $after = $lesson->fresh()->game_config['voyage_def']['legs'];
        $index = (int) ($scene->fresh()->config['leg'] ?? 0);
        foreach ($after as $i => $leg) {
            if ($i !== $index) {
                $this->assertSame($before[$i]['transport'] ?? null, $leg['transport'] ?? null, "leg {$i} should not have changed");
            }
        }
    }

    public function test_an_unknown_transport_is_refused_rather_than_saved(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();
        $component = $this->inspector($teacher, $lesson, $scene);
        $component->call('setLegTransport', 'camel');

        $component->call('setLegTransport', 'hovercraft');

        // A model that does not exist would draw nothing at all on the map, so the last real
        // choice has to survive it.
        $this->assertSame('camel', $this->leg($lesson, $scene)['transport']);
    }

    public function test_a_picture_can_travel_the_leg_instead_of_a_model(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();
        $component = $this->inspector($teacher, $lesson, $scene);

        $component->call('setLegMarkerImage', '/storage/lessons/9/sledge.jpg');
        $this->assertSame('/storage/lessons/9/sledge.jpg', $this->leg($lesson, $scene)['marker_image']);

        $component->call('clearLegMarkerImage');
        $this->assertArrayNotHasKey('marker_image', $this->leg($lesson, $scene));
    }

    public function test_choosing_a_model_drops_the_picture_so_the_choice_is_the_one_that_shows(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();
        $component = $this->inspector($teacher, $lesson, $scene);

        $component->call('setLegMarkerImage', 'https://example.test/sledge.jpg');
        $component->call('setLegTransport', 'horse');

        $leg = $this->leg($lesson, $scene);
        $this->assertSame('horse', $leg['transport']);
        $this->assertArrayNotHasKey('marker_image', $leg, 'the picture would otherwise keep winning over the model just picked');
    }

    public function test_a_marker_url_the_browser_cannot_safely_fetch_is_refused(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();
        $component = $this->inspector($teacher, $lesson, $scene);

        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'data:image/png;base64,AAAA', ''] as $bad) {
            $component->call('setLegMarkerImage', $bad);
            $this->assertArrayNotHasKey('marker_image', $this->leg($lesson, $scene), "refused: {$bad}");
        }
    }

    public function test_the_animation_slider_is_stored_and_clamped(): void
    {
        [$teacher, $lesson, $scene] = $this->voyageLesson();
        $component = $this->inspector($teacher, $lesson, $scene);

        // Unset means "as tuned", not "still" — a lesson built before the slider existed must not
        // suddenly freeze its ship.
        $this->assertSame(100, $component->instance()->voyageMap()['motion']);

        $component->call('setVoyageMap', 'motion', '35');
        $this->assertSame(35, $lesson->fresh()->game_config['voyage_map']['motion']);

        $component->call('setVoyageMap', 'motion', '400');
        $this->assertSame(100, $lesson->fresh()->game_config['voyage_map']['motion']);

        $component->call('setVoyageMap', 'motion', '-20');
        $this->assertSame(0, $lesson->fresh()->game_config['voyage_map']['motion']);
    }
}
