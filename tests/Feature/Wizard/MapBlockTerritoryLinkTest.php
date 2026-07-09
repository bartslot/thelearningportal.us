<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Map-block territory linking: the configurator's click-to-link path
 * (linkTerritoryFromMap) and the projection toggle. QIDs arrive raw from
 * MapLibre tile features, so every guard here is a trust boundary.
 */
class MapBlockTerritoryLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;
    private Scene $mapScene;

    protected function setUp(): void
    {
        parent::setUp();

        if (app()->environment() !== 'testing') {
            throw new \RuntimeException('Corpus fixtures must only run in the testing environment.');
        }

        // linkTerritory() consults the read-only corpus catalog (public.topics). In testing the
        // corpus connection points at the local test database, so build a minimal stand-in.
        $corpus = DB::connection('pgsql_corpus');
        $corpus->statement('DROP VIEW IF EXISTS public.topics');
        $corpus->statement('DROP TABLE IF EXISTS public.topics');
        $corpus->statement('
            CREATE TABLE public.topics (
                id text primary key,
                qid text,
                name text,
                region_label text,
                era_start integer,
                era_end integer,
                region_lat double precision,
                region_lng double precision,
                sitelinks integer default 0
            )');

        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Rome', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);
        $this->mapScene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'map',
            'status' => 'ready', 'config' => ['qid' => null, 'year' => null],
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql_corpus')->statement('DROP TABLE IF EXISTS public.topics');
        parent::tearDown();
    }

    private function wizard()
    {
        return Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->mapScene->id);
    }

    private function seedTopic(array $attrs = []): void
    {
        DB::connection('pgsql_corpus')->table('public.topics')->insert(array_merge([
            'id' => 'polity:roman-republic',
            'qid' => 'Q17167',
            'name' => 'Roman Republic',
            'region_label' => 'Mediterranean',
            'era_start' => -509,
            'era_end' => -27,
            'region_lat' => 41.9,
            'region_lng' => 12.5,
            'sitelinks' => 250,
        ], $attrs));
    }

    public function test_map_click_links_a_catalog_polity_and_seeds_the_year(): void
    {
        $this->seedTopic();

        $this->wizard()->dispatch('mapTerritoryClicked',
            sceneId: $this->mapScene->id, qid: 'Q17167', name: 'Roman Republic');

        $scene = $this->mapScene->fresh();
        $this->assertSame('Q17167', $scene->config['qid']);
        $this->assertSame('Roman Republic', $scene->location);
        // Year seeds to the polity's mid-life when the teacher hasn't set one.
        $this->assertSame((int) ((-509 + -27) / 2), (int) $scene->config['year']);
    }

    public function test_map_click_keeps_a_year_the_teacher_already_set(): void
    {
        $this->seedTopic();
        $this->mapScene->update(['config' => ['qid' => null, 'year' => -100]]);

        $this->wizard()->dispatch('mapTerritoryClicked',
            sceneId: $this->mapScene->id, qid: 'Q17167', name: 'Roman Republic');

        $this->assertSame(-100, (int) $this->mapScene->fresh()->config['year']);
    }

    public function test_map_click_outside_the_catalog_links_the_raw_qid_with_the_tile_name(): void
    {
        $this->wizard()->dispatch('mapTerritoryClicked',
            sceneId: $this->mapScene->id, qid: 'Q999999', name: '  Sasanian Empire  ');

        $scene = $this->mapScene->fresh();
        $this->assertSame('Q999999', $scene->config['qid']);
        $this->assertSame('Sasanian Empire', $scene->location, 'tile name is trimmed');
    }

    public function test_fallback_tile_name_is_capped_at_120_characters(): void
    {
        $this->wizard()->dispatch('mapTerritoryClicked',
            sceneId: $this->mapScene->id, qid: 'Q999999', name: str_repeat('A', 500));

        $this->assertSame(120, mb_strlen($this->mapScene->fresh()->location));
    }

    public function test_map_click_outside_the_catalog_without_a_name_is_ignored(): void
    {
        $this->wizard()->dispatch('mapTerritoryClicked',
            sceneId: $this->mapScene->id, qid: 'Q999999', name: '   ');

        $this->assertNull($this->mapScene->fresh()->config['qid']);
    }

    public function test_malformed_qids_from_the_client_are_rejected(): void
    {
        foreach (['javascript:alert(1)', 'Q12; DROP TABLE scenes', 'q17167', '17167', ''] as $bad) {
            $this->wizard()->dispatch('mapTerritoryClicked',
                sceneId: $this->mapScene->id, qid: $bad, name: 'X');
        }

        $scene = $this->mapScene->fresh();
        $this->assertNull($scene->config['qid']);
        $this->assertNull($scene->location);
    }

    public function test_stale_clicks_from_a_previously_selected_scene_are_ignored(): void
    {
        $other = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'map',
            'status' => 'ready', 'config' => ['qid' => null, 'year' => null],
        ]);

        // Selected scene is $mapScene; the click payload claims to be for $other.
        $this->wizard()->dispatch('mapTerritoryClicked',
            sceneId: $other->id, qid: 'Q999999', name: 'Sasania');

        $this->assertNull($this->mapScene->fresh()->config['qid']);
        $this->assertNull($other->fresh()->config['qid']);
    }

    public function test_projection_toggle_persists_and_coerces_garbage_to_mercator(): void
    {
        $this->wizard()->call('setProjection', 'globe');
        $this->assertSame('globe', $this->mapScene->fresh()->config['projection']);

        $this->wizard()->call('setProjection', 'stereo-nonsense');
        $this->assertSame('mercator', $this->mapScene->fresh()->config['projection']);
    }
}
