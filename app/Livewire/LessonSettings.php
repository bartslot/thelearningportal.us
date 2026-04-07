<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Lesson;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;

class LessonSettings extends Component
{
    use WithFileUploads;

    public Lesson $lesson;

    public $portrait = null;

    public string $lessonCode = '';

    public ?int $durationMinutes = null;

    public ?int $durationSeconds = null;

    public string $gradeLevel = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->lessonCode     = $this->lesson->lesson_code ?? '';
        $this->gradeLevel     = $this->lesson->grade_level ?? '';

        if ($this->lesson->duration_seconds !== null) {
            $this->durationMinutes = (int) floor($this->lesson->duration_seconds / 60);
            $this->durationSeconds = $this->lesson->duration_seconds % 60;
        }
    }

    #[Computed]
    public function currentPortraitUrl(): string
    {
        return $this->lesson->portraitUrl();
    }

    public function regenerateCode(): void
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Lesson::where('lesson_code', $code)->where('id', '!=', $this->lesson->id)->exists());

        $this->lessonCode = $code;
        $this->saved      = false;
    }

    public function save(): void
    {
        $this->validate([
            'portrait'        => 'nullable|image|max:4096',
            'lessonCode'      => [
                'required', 'string', 'max:8', 'alpha_num',
                Rule::unique('lessons', 'lesson_code')->ignore($this->lesson->id),
            ],
            'durationMinutes' => 'nullable|integer|min:0|max:999',
            'durationSeconds' => 'nullable|integer|min:0|max:59',
            'gradeLevel'      => 'required|string|max:100',
        ]);

        $updates = [
            'lesson_code' => strtoupper($this->lessonCode),
            'grade_level' => $this->gradeLevel,
        ];

        if ($this->durationMinutes !== null) {
            $updates['duration_seconds'] = ($this->durationMinutes * 60) + ($this->durationSeconds ?? 0);
        }

        if ($this->portrait) {
            $path = $this->portrait->storeAs(
                "lessons/{$this->lesson->id}",
                'portrait.jpg',
                'public',
            );
            $updates['portrait_path'] = $path;
            $this->portrait           = null;
        }

        $this->lesson->update($updates);
        $this->lesson->refresh();
        $this->saved = true;
    }

    public function updatedPortrait(): void
    {
        $this->saved = false;
    }

    public function render()
    {
        return view('livewire.lesson-settings');
    }
}
