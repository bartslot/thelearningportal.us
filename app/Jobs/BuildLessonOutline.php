<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Services\LessonOutlinePrompt;
use App\Services\OpenAiLlmService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

class BuildLessonOutline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $lessonId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with('source', 'strategyGame')->findOrFail($this->lessonId);
        $lesson->update(['status' => LessonStatus::Outlining]);

        try {
            // Defensive: previous attempts (manual retry, queue retry, user clicked Generate twice)
            // may have left Scene rows around. The (lesson_id, order) unique index would then trip
            // on re-insert. Wipe and rebuild from scratch.
            $lesson->scenes()->delete();

            $sourceText = (string) ($lesson->source?->extracted_text ?? '');

            $outline = $llm->json(
                system: LessonOutlinePrompt::system(),
                user:   LessonOutlinePrompt::user($lesson, $sourceText),
            );

            $lesson->update([
                'title'   => $outline['title'] ?? $lesson->topic,
                'outline' => $outline,
                'status'  => LessonStatus::ScenesGenerating,
            ]);

            $scenes = [];
            foreach (($outline['scene_briefs'] ?? []) as $brief) {
                $scenes[] = Scene::create([
                    'lesson_id'          => $lesson->id,
                    'order'              => (int) ($brief['order'] ?? count($scenes) + 1),
                    'kind'               => $brief['kind'] ?? 'narration',
                    'year'               => $brief['year']     ?? null,
                    'location'           => $brief['location'] ?? null,
                    'image_prompt'       => $brief['image_prompt_seed'] ?? null,
                    'image_style'        => $lesson->image_style,
                    'game_segment_index' => $brief['game_segment_index'] ?? null,
                    'status'             => 'pending',
                ]);
            }

            $jobs = [];
            foreach ($scenes as $scene) {
                $jobs[] = new GenerateSceneScript($scene->id);
                $jobs[] = new GenerateSceneImage($scene->id);
                $jobs[] = new GenerateSceneAudio($scene->id);
            }

            Bus::batch($jobs)
                ->name("lesson:{$lesson->id}:scenes")
                ->then(fn (Batch $batch) => GenerateLessonQuiz::dispatch($lesson->id))
                ->dispatch();
        } catch (Throwable $e) {
            $lesson->update([
                'status'        => LessonStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
