<?php

declare(strict_types=1);

namespace App\Livewire\Dev;

use App\Models\Lesson;
use App\Models\QuizScore;
use App\Services\DevSeeder;
use Livewire\Component;

/**
 * Local-only floating dev toolbar for exercising teacher-facing flows without
 * real student data. Mounted from the app layout behind an environment check;
 * mount() re-asserts the guard so it can never run outside local.
 */
class TestPanel extends Component
{
    public ?int $lessonId = null;

    public ?string $lessonLabel = null;

    public int $resultCount = 0;

    public string $returnUrl = '';

    public function mount(): void
    {
        abort_unless(app()->environment('local'), 404);

        $this->returnUrl = request()->fullUrl();

        // The host page is route-model-bound, so the current lesson (if any) is on the route.
        $lesson = request()->route('lesson');
        if ($lesson instanceof Lesson) {
            $this->lessonId = $lesson->id;
            $this->lessonLabel = $lesson->lesson_code ?? ($lesson->title ?? 'Lesson '.$lesson->id);
            $this->resultCount = QuizScore::where('lesson_id', $lesson->id)->count();
        }
    }

    private function lesson(): ?Lesson
    {
        return $this->lessonId ? Lesson::find($this->lessonId) : null;
    }

    public function seedResults(bool $attachClass = false): void
    {
        abort_unless(app()->environment('local'), 404);

        $lesson = $this->lesson();
        if (! $lesson) {
            return;
        }

        $made = app(DevSeeder::class)->seedResults($lesson, 12, $attachClass);
        session()->flash('dev_status', $made > 0
            ? "Seeded {$made} results".($attachClass ? ' + test class' : '').'.'
            : 'No quiz questions to seed from.');

        $this->reload();
    }

    public function clearResults(): void
    {
        abort_unless(app()->environment('local'), 404);

        $lesson = $this->lesson();
        if (! $lesson) {
            return;
        }

        $removed = app(DevSeeder::class)->clearResults($lesson);
        session()->flash('dev_status', "Cleared {$removed} results.");

        $this->reload();
    }

    /** Full-page reload of the host page so sibling Livewire components pick up the new data. */
    private function reload(): void
    {
        $this->redirect($this->returnUrl);
    }

    public function render()
    {
        return view('livewire.dev.test-panel');
    }
}
