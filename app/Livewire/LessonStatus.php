<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\LessonStatus as LessonStatusEnum;
use App\Models\Lesson;
use App\Services\ImageSearchService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class LessonStatus extends Component
{
    public Lesson $lesson;

    public function refresh(): void
    {
        $wasGenerating = $this->lesson->isGenerating() || $this->lesson->status === LessonStatusEnum::Pending;
        $this->lesson->refresh();
        $this->lesson->load('quizQuestions');

        $nowDone = ! ($this->lesson->isGenerating() || $this->lesson->status === LessonStatusEnum::Pending);

        if ($wasGenerating && $nowDone) {
            $this->dispatch('lesson-updated');
        }
    }

    #[Computed]
    public function shouldPoll(): bool
    {
        return in_array($this->lesson->status, [LessonStatusEnum::Pending, LessonStatusEnum::Generating], true);
    }

    /**
     * The 5 pipeline steps with state: done | active | failed | pending.
     * Portrait is handled separately via the Avatar — not part of lesson generation.
     */
    #[Computed]
    public function steps(): array
    {
        $lesson   = $this->lesson;
        $isFailed = $lesson->status === LessonStatusEnum::Failed;

        $europeanaConfigured = ! empty(config('services.europeana.key'));

        $steps = [
            [
                'key'         => 'fetch-wikipedia',
                'label'       => 'Fetch Wikipedia source',
                'description' => 'Pulling verified historical facts',
                'done'        => $lesson->wikipedia_source !== null,
                'skipReason'  => null,
                'canRetry'    => false,
            ],
            [
                'key'         => 'write-script',
                'label'       => 'Write lesson script',
                'description' => 'AI crafting the narrative',
                'done'        => $lesson->script !== null,
                'skipReason'  => null,
                'canRetry'    => false,
            ],
            [
                'key'         => 'generate-quiz',
                'label'       => 'Generate quiz questions',
                'description' => 'Creating comprehension checks',
                'done'        => $lesson->quizQuestions()->exists(),
                'skipReason'  => null,
                'canRetry'    => false,
            ],
            [
                'key'         => 'generate-audio',
                'label'       => 'Generate audio narration',
                'description' => 'Text-to-speech processing',
                'done'        => $lesson->audio_path !== null,
                'skipReason'  => null,
                'canRetry'    => false,
            ],
            [
                'key'         => 'fetch-images',
                'label'       => 'Fetch background images',
                'description' => 'Pulling historical images from Europeana, Wikimedia, Unsplash & Pexels',
                'done'        => $lesson->hasSlideshowImages(),
                'skipReason'  => $europeanaConfigured
                    ? 'Europeana key set — click to fetch now'
                    : 'Wikimedia fallback available — click to fetch now',
                'canRetry'    => true,
            ],
        ];

        // Find the first incomplete step
        $firstIncomplete = null;
        foreach ($steps as $i => $step) {
            if (! $step['done']) {
                $firstIncomplete = $i;
                break;
            }
        }

        $isComplete = in_array(
            $lesson->status,
            [\App\Enums\LessonStatus::Ready, \App\Enums\LessonStatus::Published],
            true
        );

        foreach ($steps as $i => &$step) {
            $step['state'] = match (true) {
                $step['done']                                        => 'done',
                $isFailed && $i === $firstIncomplete                 => 'failed',
                $lesson->isGenerating() && $i === $firstIncomplete   => 'active',
                // Lesson is complete but this optional step was never run
                $isComplete && ! $step['done']                       => 'skipped',
                default                                              => 'pending',
            };
        }
        unset($step);

        return $steps;
    }

    #[Computed]
    public function completedCount(): int
    {
        return collect($this->steps)->where('done', true)->count();
    }

    #[Computed]
    public function allStepsComplete(): bool
    {
        // fetch-images is optional — lesson is complete once the 4 core steps are done.
        $states = collect($this->steps)->pluck('state');
        return $states->filter(fn ($s) => in_array($s, ['pending', 'active'], true))->isEmpty()
            && $states->contains('done');
    }

    /**
     * Trigger regeneration of a single optional pipeline step.
     * Only skipped steps with canRetry=true can be triggered this way.
     */
    public function runStep(string $key): void
    {
        if ($this->lesson->isGenerating()) {
            return;
        }

        match ($key) {
            'fetch-images' => $this->runFetchImages(),
            default        => null,
        };

        $this->lesson->refresh();
    }

    private function runFetchImages(): void
    {
        try {
            $images = app(ImageSearchService::class)->fetchImages($this->lesson->topic, 14);
            if (! empty($images)) {
                $this->lesson->update(['slideshow_images' => $images]);
            }
        } catch (\Throwable $e) {
            // Surface nothing to the UI — the step will remain skipped
        }
    }

    public function render()
    {
        return view('livewire.lesson-status');
    }
}
