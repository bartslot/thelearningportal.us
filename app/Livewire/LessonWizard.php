<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Lesson;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class LessonWizard extends Component
{
    public ?Lesson $lesson = null;

    #[Url(as: 'step')]
    public int $step = 1;

    public function mount(?Lesson $lesson = null): void
    {
        if ($lesson?->exists) {
            abort_unless($lesson->teacher_id === auth()->id(), 403);
            $this->lesson = $lesson;
            $this->step   = max(1, min(4, (int) ($lesson->wizard_step ?? $this->step ?: 1)));
        }
    }

    public function goToStep(int $step): void
    {
        $this->step = max(1, min(4, $step));
        if ($this->lesson) {
            $this->lesson->update(['wizard_step' => $this->step]);
        }
    }

    public function render(): View
    {
        return view('livewire.lesson-wizard')
            ->layout('components.layouts.app', ['title' => 'Create Lesson']);
    }
}
