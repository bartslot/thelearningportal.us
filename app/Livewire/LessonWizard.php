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

            // If the URL has an explicit ?step=N, honor it. Otherwise fall back to
            // the lesson's last-visited step so the dashboard "resume" link still works.
            $urlStep = request()->integer('step');
            $this->step = $urlStep >= 1 && $urlStep <= 4
                ? $urlStep
                : max(1, min(4, (int) ($lesson->wizard_step ?? 1)));
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
