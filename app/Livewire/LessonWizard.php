<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Lesson;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class LessonWizard extends Component
{
    public ?Lesson $lesson = null;

    public int $step = 1;

    public function mount(?Lesson $lesson = null): void
    {
        // Parse ?step=N from the URL ourselves. Avoid Livewire's #[Url] attribute
        // because its two-way sync was rewriting the URL on every component update,
        // making it impossible to land on a different step via the address bar.
        $urlStep = request()->integer('step');
        $resolvedStep = $urlStep >= 1 && $urlStep <= 5 ? $urlStep : null;

        if ($lesson?->exists) {
            abort_unless($lesson->teacher_id === auth()->id(), 403);
            $this->lesson = $lesson;

            $this->step = $resolvedStep
                ?? max(1, min(5, (int) ($lesson->wizard_step ?? 1)));
        } else {
            $this->step = $resolvedStep ?? 1;
        }
    }

    public function goToStep(int $step): void
    {
        $this->step = max(1, min(5, $step));
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
