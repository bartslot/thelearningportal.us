<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class LessonShow extends Component
{
    public Lesson $lesson;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);

        $this->lesson = $lesson->load('quizQuestions');
    }

    #[On('lesson-updated')]
    public function refreshLesson(): void
    {
        $this->lesson->refresh();
        $this->lesson->load('quizQuestions');
    }

    #[Computed]
    public function shouldPoll(): bool
    {
        return in_array($this->lesson->status, [LessonStatus::Pending, LessonStatus::Generating], true);
    }

    public function render()
    {
        return view('livewire.lesson-show')
            ->layout('components.layouts.app', [
                'title' => $this->lesson->title,
            ]);
    }
}
