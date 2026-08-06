<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A curated city whose coordinate never resolved is stored at lat 0, lng 0 — and 0,0 is a real
 * place: the Gulf of Guinea, a few hundred kilometres off Nigeria. Served as GeoJSON, every one of
 * those cities drew as a labelled dot in the sea. For a while the whole curated gazetteer sat there
 * at once, so a history map carried "Constantinople (Istanbul)" off the African coast.
 *
 * The data has been repaired twice now and drifted back both times — the seeder's QIDs were
 * corrected in the file but not re-run on the server, so a backfill resolved the wrong Wikidata
 * items. So the guard belongs in the endpoint, where no seed state can get past it.
 */
class HistoricalCitiesNullIslandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('map.historical_cities.geojson');
    }

    public function test_a_city_stranded_at_null_island_is_not_served(): void
    {
        City::create([
            'name' => 'Ctesiphon', 'lat' => 0, 'lng' => 0, 'scalerank' => 1,
            'historical_name' => 'Ctesiphon', 'historical_period' => '120 BCE–637 CE',
        ]);

        $names = collect($this->getJson('/map/historical-cities.geojson')->json('features'))
            ->pluck('properties.historical');

        $this->assertNotContains('Ctesiphon', $names, 'a city at 0,0 was drawn in the Gulf of Guinea');
    }

    public function test_cities_with_real_coordinates_are_still_served(): void
    {
        City::create([
            'name' => 'Istanbul', 'lat' => 41.01, 'lng' => 28.96, 'scalerank' => 1,
            'historical_name' => 'Constantinople', 'historical_period' => '330–1453 CE',
        ]);

        $features = $this->getJson('/map/historical-cities.geojson')->json('features');

        $this->assertCount(1, $features);
        $this->assertSame('Constantinople', $features[0]['properties']['historical']);
        $this->assertSame([28.96, 41.01], $features[0]['geometry']['coordinates']);
    }

    public function test_a_city_on_the_equator_or_the_prime_meridian_is_not_mistaken_for_a_stub(): void
    {
        // Only BOTH being zero means "never resolved". Quito sits on the equator; Accra is within a
        // few kilometres of the prime meridian. Neither is a stub, and a laxer guard would eat them.
        City::create([
            'name' => 'Quito', 'lat' => 0, 'lng' => -78.47, 'scalerank' => 1,
            'historical_name' => 'Quitu', 'historical_period' => 'pre-1534',
        ]);
        City::create([
            'name' => 'Tema', 'lat' => 5.67, 'lng' => 0, 'scalerank' => 3,
            'historical_name' => 'Torman', 'historical_period' => 'antiquity',
        ]);

        $names = collect($this->getJson('/map/historical-cities.geojson')->json('features'))
            ->pluck('properties.historical');

        $this->assertContains('Quitu', $names);
        $this->assertContains('Torman', $names);
    }
}
