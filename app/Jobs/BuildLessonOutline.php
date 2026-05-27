<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\LessonSource;
use App\Services\LessonOutlinePrompt;
use App\Services\OpenAiLlmService;
use App\Services\WikipediaService;
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

        try {
            // ── Step 1: Fetch Wikipedia (deferred from web request for speed) ──
            $source = $lesson->source;
            if ($source && in_array($source->kind, ['wikipedia', 'both'], true)
                && (string) $source->extracted_text === '') {

                $lesson->update(['status' => LessonStatus::FetchingSources]);

                $wiki = app(WikipediaService::class)->fetchFacts($lesson->topic) ?? '';

                $combined = $wiki;
                // For 'both' mode the document text was already extracted and stored
                if ($source->kind === 'both') {
                    $combined = trim($source->extracted_text . "\n\n" . $wiki);
                }

                $source->update(['extracted_text' => $combined, 'wikipedia_topic' => $lesson->topic]);
            }

            $lesson->update(['status' => LessonStatus::Outlining]);

            // Defensive: previous attempts (manual retry, queue retry, user clicked Generate twice)
            // may have left Scene rows around. The (lesson_id, order) unique index would then trip
            // on re-insert. Wipe and rebuild from scratch.
            $lesson->scenes()->delete();

            $lesson->refresh();
            $sourceText = (string) ($lesson->source?->extracted_text ?? '');

            $hasGame = $lesson->strategyGame !== null;
            $outline = $llm->json(
                system: LessonOutlinePrompt::system($hasGame),
                user:   LessonOutlinePrompt::user($lesson, $sourceText),
            );

            $lesson->update([
                'title'   => $outline['title'] ?? $lesson->topic,
                'outline' => $outline,
                'status'  => LessonStatus::ScenesGenerating,
            ]);

            // Ignore any "order" the LLM emitted — assign sequential 1-based positions so
            // we never trip the (lesson_id, order) unique index when the model repeats numbers.
            $scenes = [];
            foreach (($outline['scene_briefs'] ?? []) as $idx => $brief) {
                $scenes[] = Scene::create([
                    'lesson_id'          => $lesson->id,
                    'order'              => $idx + 1,
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
