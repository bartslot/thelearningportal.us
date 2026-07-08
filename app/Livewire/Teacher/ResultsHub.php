<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use Livewire\Component;

class ResultsHub extends Component
{
    public function render()
    {
        return view('livewire.teacher.results-hub')
            ->layout('components.layouts.app', ['title' => 'Results']);
    }
}
