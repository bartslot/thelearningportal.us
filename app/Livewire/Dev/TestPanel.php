<?php

declare(strict_types=1);

namespace App\Livewire\Dev;

use App\Models\Lesson;
use App\Models\QuizScore;
use App\Services\DevPaperSheet;
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

    /**
     * Generate a fake "photo" of two filled answer sheets for this lesson's real
     * quiz questions, so the paper-import (vision) flow can be tested end to end.
     * One sheet answers everything correctly, the other is mixed — grading is
     * therefore predictable after import.
     */
    public function downloadPaperSheets()
    {
        abort_unless(app()->environment('local'), 404);

        $lesson = $this->lesson();
        if (! $lesson) {
            return null;
        }

        $questions = $lesson->quizQuestions()->orderBy('scene_id')->orderBy('order')->get();
        if ($questions->isEmpty()) {
            session()->flash('dev_status', 'This lesson has no quiz questions — paper import grades against real questions, so generate a quiz first.');
            $this->redirect($this->returnUrl);

            return null;
        }

        $correct = $questions->map(fn ($q) => chr(65 + (int) $q->correct_index))->all();
        $mixed = array_map(
            fn (string $c, int $i) => $i % 2 === 0 ? $c : ($c === 'A' ? 'B' : 'A'),
            $correct,
            array_keys($correct),
        );

        $png = app(DevPaperSheet::class)->png([
            ['name' => 'Emma V.', 'answers' => $correct],
            ['name' => 'Liam B.', 'answers' => $mixed],
        ], $questions->count());

        $filename = 'test-sheets-'.($lesson->lesson_code ?? $lesson->id).'.png';

        return response()->streamDownload(fn () => print($png), $filename, ['Content-Type' => 'image/png']);
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
