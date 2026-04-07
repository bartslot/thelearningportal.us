<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\LessonStatus;
use App\Jobs\GenerateLesson;
use App\Models\Avatar;
use App\Models\Lesson;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateLesson extends Component
{
    public array $topicSuggestions = [
        'Ancient Egypt',
        'Ancient Greece',
        'Roman Empire',
        'Medieval Europe',
        'The Renaissance',
        'The Age of Exploration',
        'American Revolution',
        'French Revolution',
        'Industrial Revolution',
        'Civil Rights Movement',
        'Women\'s Suffrage',
        'World War I',
        'World War II',
        'Cold War',
        'Fall of the Berlin Wall',
        'The Great Depression',
        'The Vietnam War',
        'The Iran-Iraq War',
        'The Ottoman Empire',
        'The Silk Road',
    ];

    #[Validate('required|string|min:3|max:100')]
    public string $topic = '';

    #[Validate('required|in:history,science,literature,civics')]
    public string $subject = 'history';

    #[Validate('required|string|min:3|max:50')]
    public string $grade_level = '9th grade';

    #[Validate('nullable|string|max:150')]
    public string $tone = '';

    #[Validate('nullable|string|max:500')]
    public string $details = '';

    #[Validate('nullable|exists:avatars,id')]
    public ?int $avatar_id = null;

    public function mount(): void
    {
        // Default to the first active avatar
        $default = Avatar::active()->first();
        if ($default) {
            $this->avatar_id = $default->id;
        }
    }

    #[Computed]
    public function avatars()
    {
        return Avatar::active()->get();
    }

    public function submit(): void
    {
        $this->validate();

        $lesson = Lesson::create([
            'teacher_id'  => auth()->id(),
            'avatar_id'   => $this->avatar_id,
            'topic'       => trim($this->topic),
            'subject'     => $this->subject,
            'grade_level' => $this->grade_level,
            'tone'        => trim($this->tone) ?: null,
            'details'     => trim($this->details) ?: null,
            'status'      => LessonStatus::Pending,
        ]);

        GenerateLesson::dispatch($lesson->id);

        $this->redirect(route('teacher.lessons.show', $lesson->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.create-lesson')
            ->layout('components.layouts.app', [
                'title' => 'Create Lesson',
            ]);
    }
}
