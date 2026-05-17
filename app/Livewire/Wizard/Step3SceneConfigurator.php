<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Models\Lesson;
use Livewire\Component;

class Step3SceneConfigurator extends Component
{
    public ?Lesson $lesson = null;

    public function render()
    {
        return view('livewire.wizard.step3-scene-configurator');
    }
}
