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
            abort_unless(auth()->user()?->canManage($lesson), 403);
            $this->lesson = $lesson;

            $this->step = $resolvedStep
                ?? max(1, min(5, (int) ($lesson->wizard_step ?? 1)));

            // The Generate screen (3) is meaningless for lessons with nothing to generate —
            // e.g. command-built voyage lessons whose scenes are all ready and none narrated.
            // Landing there showed an eternal "Researching sources 2%"; go straight to Configure.
            if ($this->step === 3 && $this->hasNothingToGenerate($lesson)) {
                $this->step = 4;
            }
        } else {
            $this->step = $resolvedStep ?? 1;
        }
    }

    public function goBackStep()
    {
        // Check if we are currently in Step 4 or 5 before allowing the move back
        if (in_array((int) $this->step, [5])) {
            $this->step = 4; // Programmatically set the step to 3
            // OPTIONAL: If you need to load data specific to Step 3 when going back:
            // $this->loadStep3Data();
        }
    }

    /** True when the lesson has scenes, all ready, and none of the narration kind — nothing for
     *  the generation pipeline to do, so the Generate step would sit at a bogus low percentage. */
    private function hasNothingToGenerate(Lesson $lesson): bool
    {
        return $lesson->scenes()->exists()
            && ! $lesson->scenes()->where('kind', 'narration')->exists()
            && ! $lesson->scenes()->where('status', '!=', 'ready')->exists();
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
            ->layout('components.layouts.app', [
                'title' => 'Create Lesson',
                // Configure (4) and Preview (5) are full-screen canvas editors — hide the global nav.
                'hideChrome' => in_array($this->step, [4, 5], true),
            ]);
    }
}
