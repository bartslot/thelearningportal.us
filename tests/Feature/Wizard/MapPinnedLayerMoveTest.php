<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\SvgAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Moving an icon that is pinned to a place on a map.
 *
 * A map layer is anchored to a lng/lat, and its x/y is only where the current camera happens to put
 * it. The editor's move handler reported x/y alone, so the coordinates kept their old values and the
 * next render put the icon straight back where it started — which is exactly what Bart saw.
 *
 * The server was always able to do this correctly; the caller simply never sent the place. These
 * tests pin the server contract, including the deliberate "no anchor means leave it alone" rule that
 * made the bug invisible.
 */
class MapPinnedLayerMoveTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    private Scene $scene;

    private SvgAsset $icon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::create([
            'name' => 'Teacher', 'email' => 'teacher@example.com',
            'password' => 'secret-password', 'role' => 'teacher',
        ]);

        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id, 'title' => 'Silk Road', 'topic' => 'Silk Road',
            'subject' => 'history', 'grade_level' => '7', 'status' => LessonStatus::Configuring,
        ]);

        $this->icon = SvgAsset::create([
            'user_id' => null, 'source' => 'library', 'source_ref' => 'camel', 'title' => 'Camel',
            'svg_path' => 'icons/transport/camel.svg', 'source_url' => 'icons/transport/camel.svg',
            'license' => 'own',
        ]);

        // Pinned over Venice, which is where the icon in Bart's screenshot sits.
        $this->scene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'map', 'status' => 'ready',
            'script' => 'The Silk Road.', 'config' => ['year' => 1271],
            'shots' => [[
                'order' => 0,
                'layers' => [[
                    'asset_id' => $this->icon->id, 'path' => 'icons/transport/camel.svg',
                    'kind' => 'figure', 'x' => 30.0, 'y' => 40.0, 'scale' => 1.0,
                    'anchor' => 'map', 'lng' => 12.34, 'lat' => 45.44,
                ]],
            ]],
        ]);
    }

    private function layer(): array
    {
        return $this->scene->refresh()->shots[0]['layers'][0];
    }

    public function test_a_move_that_carries_the_new_place_updates_the_coordinates(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('moveArtworkLayer', $this->icon->id, 62.0, 55.0, 1.0, 'map', 69.24, 41.31);

        $layer = $this->layer();
        $this->assertEqualsWithDelta(69.24, $layer['lng'], 0.0001, 'the pin has to follow the drag, or the next render undoes it');
        $this->assertEqualsWithDelta(41.31, $layer['lat'], 0.0001);
        $this->assertEqualsWithDelta(62.0, $layer['x'], 0.0001);
    }

    public function test_a_move_reporting_only_x_and_y_leaves_the_old_place_behind(): void
    {
        // The bug, pinned as behaviour rather than removed. The server deliberately treats a missing
        // anchor as "this caller does not know about pinning, leave it alone" — which is right, and
        // is exactly why the editor sending x/y alone produced an icon that snapped back with
        // nothing in the code looking wrong.
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('moveArtworkLayer', $this->icon->id, 62.0, 55.0, 1.0);

        $layer = $this->layer();
        $this->assertEqualsWithDelta(12.34, $layer['lng'], 0.0001, 'unchanged, by design — the caller said nothing about the place');
        $this->assertEqualsWithDelta(62.0, $layer['x'], 0.0001, 'and x/y did move, which is what made it look like it worked');
    }

    public function test_a_projector_that_cannot_resolve_a_point_does_not_strand_the_layer(): void
    {
        // The editor sends anchor:null when unproject() fails. That must keep the existing pin
        // rather than leaving the icon anchored to nowhere.
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('moveArtworkLayer', $this->icon->id, 62.0, 55.0, 1.0, null, null, null);

        $layer = $this->layer();
        $this->assertSame('map', $layer['anchor']);
        $this->assertEqualsWithDelta(12.34, $layer['lng'], 0.0001);
    }

    public function test_coordinates_are_clamped_to_the_real_world(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->scene->id)
            ->call('moveArtworkLayer', $this->icon->id, 50.0, 50.0, 1.0, 'map', 999.0, -999.0);

        $layer = $this->layer();
        $this->assertEqualsWithDelta(180.0, $layer['lng'], 0.0001);
        $this->assertEqualsWithDelta(-90.0, $layer['lat'], 0.0001);
    }
}
