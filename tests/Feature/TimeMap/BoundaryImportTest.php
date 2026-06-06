<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Models\Corpus\Boundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class BoundaryImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    private string $hbDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();

        $this->hbDir = storage_path('framework/testing/hb');
        File::ensureDirectoryExists($this->hbDir);
        File::cleanDirectory($this->hbDir);
        File::put(
            $this->hbDir.'/world_bc500.geojson',
            json_encode([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['NAME' => 'Roman Republic'],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                        [12.0, 41.0], [13.0, 41.0], [13.0, 42.0], [12.0, 42.0], [12.0, 41.0],
                    ]]],
                ]],
            ])
        );
    }

    public function test_import_loads_polygons_with_geom_and_region_tag(): void
    {
        $this->artisan('timemap:import-boundaries', ['--path' => $this->hbDir])->assertExitCode(0);

        $b = Boundary::where('polity_id', 'roman-republic')->firstOrFail();
        $this->assertSame('Mediterranean', $b->extra['region']);
        $this->assertSame('historical-basemaps', $b->source);

        $hasGeom = DB::connection('pgsql_corpus')->selectOne(
            'select ST_Covers(geom, ST_SetSRID(ST_Point(12.5,41.5),4326)::geography) hit from public.boundaries where polity_id=?',
            ['roman-republic']
        );
        $this->assertTrue((bool) $hasGeom->hit);
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('timemap:import-boundaries', ['--path' => $this->hbDir])->assertExitCode(0);
        $this->artisan('timemap:import-boundaries', ['--path' => $this->hbDir])->assertExitCode(0);

        $this->assertSame(1, Boundary::where('polity_id', 'roman-republic')->count());
    }
}
