<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Lesson;
use App\Models\LessonFeedback;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * One-tap teacher rating on the lesson preview: 1-5 stars + optional comment.
 * Closes the quality feedback loop the pilot has been missing — engine changes
 * can be judged against real teacher ratings instead of anecdotes.
 */
class LessonFeedbackWidget extends Component
{
    public Lesson $lesson;

    #[Validate('required|integer|min:1|max:5')]
    public int $rating = 0;

    #[Validate('nullable|string|max:2000')]
    public string $comment = '';

    public bool $saved = false;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;

        $existing = LessonFeedback::where('lesson_id', $lesson->id)
            ->where('teacher_id', auth()->id())
            ->first();
        if ($existing) {
            $this->rating = $existing->rating;
            $this->comment = (string) $existing->comment;
        }
    }

    public function rate(int $stars): void
    {
        $this->rating = max(1, min(5, $stars));
        $this->save();
    }

    public function save(): void
    {
        $this->validate();

        LessonFeedback::updateOrCreate(
            ['lesson_id' => $this->lesson->id, 'teacher_id' => auth()->id()],
            ['rating' => $this->rating, 'comment' => trim($this->comment) ?: null],
        );

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.lesson-feedback-widget');
    }
}
