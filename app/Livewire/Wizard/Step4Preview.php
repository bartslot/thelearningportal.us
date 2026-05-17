<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Step4Preview extends Component
{
    public Lesson $lesson;
    public string $publishError = '';

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;

        if ($this->lesson->status !== LessonStatus::Published) {
            $this->lesson->update(['status' => LessonStatus::Previewable, 'wizard_step' => 4]);
        }
    }

    #[Computed]
    public function scenes()
    {
        return $this->lesson->scenes()->ordered()->get();
    }

    #[Computed]
    public function allReady(): bool
    {
        return $this->scenes->isNotEmpty()
            && $this->scenes->every(fn ($s) => $s->status === 'ready');
    }

    public function publish(): void
    {
        if (! $this->allReady) {
            $this->publishError = 'All scenes must be in "ready" status before publishing.';
            return;
        }

        $this->lesson->update(['status' => LessonStatus::Published]);
        $this->publishError = '';
        $this->dispatch('lesson-published');
    }

    public function render()
    {
        return view('livewire.wizard.step4-preview');
    }
}
