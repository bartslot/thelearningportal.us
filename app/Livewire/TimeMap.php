<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;

class TimeMap extends Component
{
    /** Seeded snapshot stops shown on the slider. */
    public array $stops = [-2000, -1500, -500, 200, 500, 1000, 1880];

    public int $year = 1600;

    public function render()
    {
        return view('livewire.time-map')
            ->layout('components.layouts.app', ['title' => 'Time-Map']);
    }
}
