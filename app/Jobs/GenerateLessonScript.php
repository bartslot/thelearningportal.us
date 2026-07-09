<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Services\LessonScriptPrompt;
use App\Services\OpenAiLlmService;
use App\Services\ScriptCritiquePrompt;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes ALL scene scripts in one LLM call so the lesson reads as one connected story
 * (but/therefore causality, recurring characters) instead of N isolated diorama scenes,
 * then runs one critique-and-revise pass before fanning out image + audio jobs.
 *
 * Replaces the per-scene GenerateSceneScript dispatches — and structurally fixes the old
 * script/audio race (audio jobs only dispatch after every script exists).
 */
class GenerateLessonScript implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Two LLM calls + a possible regeneration. */
    public int $timeout = 300;

    public function __construct(public readonly int $lessonId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with(['source', 'teacher', 'scenes'])->findOrFail($this->lessonId);

        try {
            // Map blocks render the live atlas — they have no narration.
            $scenes = $lesson->scenes->where('kind', '!=', 'map')->sortBy('order')->values();
            if ($scenes->isEmpty()) {
                throw new \RuntimeException('No narratable scenes to script.');
            }

            // Briefs align with narratable scenes by POSITION, not by scene order — the default
            // map block shifts every order by one, so order-based indexing reads the wrong brief.
            $briefs = array_values($lesson->outline['scene_briefs'] ?? []);
            $sourceText = (string) ($lesson->source?->extracted_text ?? '');

            $scenes->each(fn (Scene $scene) => $scene->update(['status' => 'generating']));

            $scripts = $this->generateScripts($llm, $lesson, $scenes, $briefs, $sourceText);

            // ── Critique-and-revise: one judge call, at most one regeneration ──
            $critique = $this->critique($llm, $lesson, $scripts);
            if ($critique !== null && ScriptCritiquePrompt::needsRevision($critique)) {
                Log::info("GenerateLessonScript #{$lesson->id}: revising after critique", $critique);
                $issues = array_values(array_filter((array) ($critique['issues'] ?? [])));
                $scripts = $this->generateScripts($llm, $lesson, $scenes, $briefs, $sourceText, $issues);
                $critique = $this->critique($llm, $lesson, $scripts) ?? $critique;
            }

            foreach ($scenes as $scene) {
                $scene->update(['script_segment' => trim((string) $scripts->get($scene->order, ''))]);
            }

            if ($critique !== null) {
                $lesson->update(['outline' => array_merge($lesson->outline ?? [], ['critique' => $critique])]);
            }

            $this->dispatchAssetJobs($lesson, $scenes);

            // Story-game print pack: render now that script_segments exist (event cards quote them).
            // Fire-and-forget — GenerateGamePack::failed() never fails the lesson.
            if ($lesson->game_type === 'story_game' && ! empty(($lesson->game_config ?? [])['print_pack'])) {
                \App\Jobs\GenerateGamePack::dispatch($lesson->id);
            }
        } catch (Throwable $e) {
            $lesson->update([
                'status' => LessonStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * One whole-lesson LLM call. Retries once internally when the model skips a scene —
     * a missing script would otherwise surface as a silent empty narration downstream.
     *
     * @param  Collection<int, Scene>  $scenes
     * @param  list<array<string, mixed>>  $briefs
     * @param  list<string>  $critiqueIssues
     * @return Collection<int, string> scripts keyed by scene order
     */
    private function generateScripts(
        OpenAiLlmService $llm,
        Lesson $lesson,
        Collection $scenes,
        array $briefs,
        string $sourceText,
        array $critiqueIssues = [],
    ): Collection {
        $expectedOrders = $scenes->pluck('order')->all();

        foreach ([1, 2] as $attempt) {
            $result = $llm->json(
                system: LessonScriptPrompt::system($lesson),
                user: LessonScriptPrompt::user($lesson, $scenes, $briefs, $sourceText, $critiqueIssues),
            );

            $scripts = collect($result['scenes'] ?? [])
                ->filter(fn ($entry) => is_array($entry) && trim((string) ($entry['script'] ?? '')) !== '')
                ->mapWithKeys(fn (array $entry) => [(int) ($entry['order'] ?? 0) => (string) $entry['script']]);

            $missing = array_values(array_diff($expectedOrders, $scripts->keys()->all()));
            if ($missing === []) {
                return $scripts;
            }

            Log::warning("GenerateLessonScript #{$lesson->id}: attempt {$attempt} missing scenes", ['orders' => $missing]);
        }

        throw new \RuntimeException('LLM failed to script every scene (orders missing after retry).');
    }

    /**
     * Judge the draft. A critique failure must never kill the lesson — on any error we
     * accept the draft and move on.
     *
     * @param  Collection<int, string>  $scripts
     * @return array<string, mixed>|null
     */
    private function critique(OpenAiLlmService $llm, Lesson $lesson, Collection $scripts): ?array
    {
        try {
            $critique = $llm->json(
                system: ScriptCritiquePrompt::system(),
                user: ScriptCritiquePrompt::user($lesson, $scripts),
            );

            return array_intersect_key(
                $critique,
                array_flip([...ScriptCritiquePrompt::SCORE_KEYS, 'issues']),
            ) ?: null;
        } catch (Throwable $e) {
            Log::warning("GenerateLessonScript #{$lesson->id}: critique failed — accepting draft. {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Fan out per-scene visual + audio jobs, then the quiz. Visuals use the multi-shot
     * grid pipeline when enabled (config lessons.shot_grid); scenes that already carry an
     * image (worldhistory.org hero pre-assignment) skip visual generation entirely.
     *
     * @param  Collection<int, Scene>  $scenes
     */
    private function dispatchAssetJobs(Lesson $lesson, Collection $scenes): void
    {
        $useShots = \App\Services\Support\GridSlicer::parseGrid((string) config('lessons.shot_grid', '3x3')) !== null;

        $jobs = [];
        foreach ($scenes as $scene) {
            if ($scene->image_path === null) {
                $jobs[] = $useShots ? new GenerateSceneShots($scene->id) : new GenerateSceneImage($scene->id);
            }
            $jobs[] = new GenerateSceneAudio($scene->id);
        }

        Bus::batch($jobs)
            ->name("lesson:{$lesson->id}:scenes")
            ->then(fn (Batch $batch) => GenerateLessonQuiz::dispatch($lesson->id))
            ->dispatch();
    }
}
