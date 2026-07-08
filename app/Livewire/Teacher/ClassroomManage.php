<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Models\Classroom;
use App\Models\Lesson;
use App\Services\Support\NameMatcher;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ClassroomManage extends Component
{
    public Classroom $classroom;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:50')]
    public string $grade = '';

    public bool $isActive = true;

    public string $newMember = '';

    public ?int $editingMemberId = null;

    public string $editingMemberName = '';

    public string $notice = '';

    public function mount(Classroom $classroom): void
    {
        abort_unless($classroom->teacher_id === auth()->id(), 403);
        $this->classroom = $classroom;
        $this->name = $classroom->name;
        $this->grade = $classroom->grade_level ?? '';
        $this->isActive = (bool) $classroom->is_active;
    }

    #[Computed]
    public function members()
    {
        return $this->classroom->members()->orderBy('display_name')->get();
    }

    #[Computed]
    public function assignedLessons()
    {
        return $this->classroom->lessons()->orderByDesc('classroom_lessons.assigned_at')->get();
    }

    #[Computed]
    public function assignableLessons()
    {
        $assigned = $this->classroom->lessons()->pluck('lessons.id');

        return Lesson::where('teacher_id', auth()->id())
            ->whereNotIn('id', $assigned)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    public function saveDetails(): void
    {
        $this->validate();
        $this->classroom->update([
            'name' => trim($this->name),
            'grade_level' => trim($this->grade) ?: null,
            'is_active' => $this->isActive,
        ]);
        $this->notice = __('Class details saved.');
    }

    public function addMember(): void
    {
        $this->resetValidation();
        $name = NameMatcher::canonical($this->newMember);
        if ($name === '') {
            $this->addError('newMember', __('Enter a name.'));

            return;
        }
        if ($this->nameExists($name)) {
            $this->addError('newMember', __('":name" is already in this class.', ['name' => $name]));

            return;
        }

        $this->classroom->members()->create(['display_name' => $name]);
        $this->reset('newMember');
        unset($this->members);
        $this->notice = __('Child added.');
    }

    public function startEdit(int $memberId): void
    {
        $member = $this->classroom->members()->findOrFail($memberId);
        $this->editingMemberId = $member->id;
        $this->editingMemberName = $member->display_name;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingMemberId', 'editingMemberName');
    }

    public function saveMember(): void
    {
        $this->resetValidation();
        if ($this->editingMemberId === null) {
            return;
        }
        $member = $this->classroom->members()->findOrFail($this->editingMemberId);

        $name = NameMatcher::canonical($this->editingMemberName);
        if ($name === '') {
            $this->addError('editingMemberName', __('Enter a name.'));

            return;
        }
        if ($this->nameExists($name, $member->id)) {
            $this->addError('editingMemberName', __('":name" is already in this class.', ['name' => $name]));

            return;
        }

        $member->update(['display_name' => $name]);
        $this->reset('editingMemberId', 'editingMemberName');
        unset($this->members);
        $this->notice = __('Name updated.');
    }

    public function removeMember(int $memberId): void
    {
        // nullOnDelete keeps this child's past quiz scores (they revert to the anonymous nickname).
        $this->classroom->members()->findOrFail($memberId)->delete();
        unset($this->members);
        $this->notice = __('Child removed.');
    }

    public function assignLesson(int $lessonId): void
    {
        if ($lessonId <= 0) {   // the empty "Assign a lesson…" placeholder
            return;
        }

        // Only the teacher's own lessons may be attached.
        $lesson = Lesson::where('teacher_id', auth()->id())->findOrFail($lessonId);
        $this->classroom->lessons()->syncWithoutDetaching([$lesson->id => ['assigned_at' => now()]]);

        unset($this->assignedLessons, $this->assignableLessons);
        $this->notice = __('Lesson assigned.');
    }

    public function unassignLesson(int $lessonId): void
    {
        $this->classroom->lessons()->detach($lessonId);

        unset($this->assignedLessons, $this->assignableLessons);
        $this->notice = __('Lesson removed from class.');
    }

    private function nameExists(string $name, ?int $exceptId = null): bool
    {
        return $this->classroom->members()
            ->whereRaw('lower(display_name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    public function render()
    {
        return view('livewire.teacher.classroom-manage')
            ->layout('components.layouts.app', ['title' => $this->classroom->name]);
    }
}
