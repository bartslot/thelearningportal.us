<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Corpus\Article;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TimeMap extends Component
{
    /** Seeded snapshot stops shown on the slider. */
    public array $stops = [-2000, -1500, -500, 200, 500, 1000, 1880];

    public int $year = -200;

    public ?string $selectedRegion = null;

    public ?string $selectedPolity = null;

    public array $stories = [];

    /** Boundaries valid at the current year, as a GeoJSON FeatureCollection for MapLibre. */
    public function boundariesGeoJson(): array
    {
        $rows = DB::connection('pgsql_corpus')->select(
            "select polity_id, name, extra->>'region' as region,
                    ST_AsGeoJSON(geom) as geometry
             from public.boundaries
             where valid_from <= ? and valid_to >= ?",
            [$this->year, $this->year]
        );

        return [
            'type' => 'FeatureCollection',
            'features' => array_map(fn ($r) => [
                'type' => 'Feature',
                'properties' => ['polity_id' => $r->polity_id, 'name' => $r->name, 'region' => $r->region],
                'geometry' => json_decode($r->geometry, true),
            ], $rows),
        ];
    }

    /** A map click: find the polity at (lng,lat) for the year, then its region's articles. */
    public function storiesAt(float $lng, float $lat, int $year): void
    {
        $this->year = $year;

        $polity = DB::connection('pgsql_corpus')->selectOne(
            "select polity_id, name, extra->>'region' as region
             from public.boundaries
             where valid_from <= ? and valid_to >= ?
               and ST_Contains(geom, ST_SetSRID(ST_Point(?, ?), 4326))
             limit 1",
            [$year, $year, $lng, $lat]
        );

        if (! $polity) {
            $this->selectedRegion = null;
            $this->selectedPolity = null;
            $this->stories = [];

            return;
        }

        $this->selectedRegion = $polity->region;
        $this->selectedPolity = $polity->name;

        $this->stories = Article::query()
            ->forRegionYear($polity->region, $year)
            ->orderByRaw('(era_end - era_start) asc') // tighter-era (more specific) first
            ->limit(20)
            ->get(['id', 'title', 'summary', 'source_url', 'era_start', 'era_end'])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.time-map')
            ->layout('components.layouts.app', ['title' => 'Time-Map']);
    }
}
