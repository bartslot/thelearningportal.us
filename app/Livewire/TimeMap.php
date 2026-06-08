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

    /**
     * Load the articles for a clicked region + year. The spatial hit-test (which polity was
     * clicked) is done client-side from the already-loaded boundary GeoJSON, so this is the
     * only server round-trip — a single, indexed article fetch against the remote DB.
     */
    public function storiesForRegion(?string $region, ?string $polity, int $year): void
    {
        $this->year = $year;
        $this->selectedRegion = $region;
        $this->selectedPolity = $polity;

        if ($region === null || $region === '') {
            $this->stories = [];

            return;
        }

        $rows = DB::connection('pgsql_corpus')->select(
            'select id, title, summary, source_url, era_start, era_end
             from public.history_articles
             where region = ?
               and era_start <= ? and era_end >= ?
             order by (era_end - era_start) asc
             limit 20',
            [$region, $year, $year]
        );

        $this->stories = array_map(fn ($r) => (array) $r, $rows);
    }

    public function render()
    {
        return view('livewire.time-map')
            ->layout('components.layouts.app', ['title' => 'Time-Map']);
    }
}
