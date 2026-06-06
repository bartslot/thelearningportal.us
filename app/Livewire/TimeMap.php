<?php

declare(strict_types=1);

namespace App\Livewire;

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

    /**
     * A map click: find the polity at (lng,lat) for the year and its region's articles.
     * Done in a single round-trip — the DB is remote, so each query is ~0.5s of latency.
     */
    public function storiesAt(float $lng, float $lat, int $year): void
    {
        $this->year = $year;

        $rows = DB::connection('pgsql_corpus')->select(
            "with hit as (
                select name, extra->>'region' as region
                from public.boundaries
                where valid_from <= ? and valid_to >= ?
                  and ST_Covers(geom, ST_SetSRID(ST_Point(?, ?), 4326)::geography)
                limit 1
            )
            select hit.name as polity, hit.region as region,
                   a.id, a.title, a.summary, a.source_url, a.era_start, a.era_end
            from hit
            left join public.history_articles a
              on a.region = hit.region
             and a.era_start <= ? and a.era_end >= ?
            order by (a.era_end - a.era_start) asc nulls last
            limit 20",
            [$year, $year, $lng, $lat, $year, $year]
        );

        if ($rows === []) {
            $this->selectedRegion = null;
            $this->selectedPolity = null;
            $this->stories = [];

            return;
        }

        $this->selectedPolity = $rows[0]->polity;
        $this->selectedRegion = $rows[0]->region;
        $this->stories = collect($rows)
            ->filter(fn ($r) => $r->id !== null) // LEFT JOIN yields one null row when no articles
            ->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'summary' => $r->summary,
                'source_url' => $r->source_url,
                'era_start' => $r->era_start,
                'era_end' => $r->era_end,
            ])
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.time-map')
            ->layout('components.layouts.app', ['title' => 'Time-Map']);
    }
}
