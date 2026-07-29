<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Models\Classroom;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Classrooms extends Component
{
    #[Validate('required|string|max:255')]
    public string $newName = '';

    #[Validate('nullable|string|max:50')]
    public string $newGrade = '';

    public string $notice = '';

    #[Computed]
    public function classes()
    {
        return Classroom::ownedByCurrentUser()
            ->withCount(['members', 'lessons'])
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->validate();

        Classroom::create([
            'teacher_id' => auth()->id(),
            'name' => trim($this->newName),
            'grade_level' => trim($this->newGrade) ?: null,
        ]);

        $this->reset('newName', 'newGrade');
        unset($this->classes);
        $this->notice = __('Class created.');
    }

    public function delete(int $id): void
    {
        // Scope by teacher so a forged id can't delete another teacher's class.
        Classroom::ownedByCurrentUser()->findOrFail($id)->delete();

        unset($this->classes);
        $this->notice = __('Class deleted.');
    }

    public function render()
    {
        return view('livewire.teacher.classrooms')
            ->layout('components.layouts.app', ['title' => __('Classes')]);
    }
}
