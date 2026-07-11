<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Models\Scene;
use App\Services\FigureAppearanceService;
use App\Services\HistoricalImageValidationPrompt;
use App\Services\OpenAiImageService;
use App\Services\OpenAiLlmService;
use App\Services\ShotListPrompt;
use App\Services\Support\GridSlicer;
use App\Services\Support\HatchEngraver;
use App\Services\Support\ImageStyleTemplate;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Multi-image scene generation, one image-gen call per scene: an LLM storyboard
 * (shots anchored to verbatim script sentences) is rendered as ONE rows×cols grid
 * image, sliced into cells, and each cell upscaled. The player shows each shot at
 * the moment the narrator speaks its anchor sentence.
 */
class GenerateSceneShots implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, MarksSceneReady, Queueable, SerializesModels;

    public int $tries = 3;

    /** Two LLM calls + one image render that can take minutes at 1536x1024. */
    public int $timeout = 420;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiImageService $image, OpenAiLlmService $llm, FigureAppearanceService $appearance): void
    {
        $scene = Scene::with('lesson.scenes', 'lesson.source')->findOrFail($this->sceneId);

        $grid = GridSlicer::parseGrid((string) config('lessons.shot_grid', '3x3'));
        if ($grid === null || trim((string) $scene->script_segment) === '') {
            // Shots disabled or nothing to storyboard — legacy single-image pipeline.
            GenerateSceneImage::dispatch($this->sceneId);

            return;
        }
        [$rows, $cols] = $grid;
        $shotCount = $rows * $cols;

        try {
            $shots = $this->storyboard($llm, $scene, $shotCount);
            $validation = $this->validateVisuals($llm, $appearance, $scene);

            $prompt = ImageStyleTemplate::buildShotGrid(
                panelDescriptions: array_column($shots, 'description'),
                validation: $validation,
                style: (string) ($scene->image_style ?? $scene->lesson->image_style ?? 'realistic'),
                rows: $rows,
                cols: $cols,
            );

            $bytes = $image->generateGridBytesFromPrompt(
                prompt: $prompt,
                size: (string) config('services.openai.scene_size', config('services.openai.image_size', '1536x1024')),
            );

            // Gutter-aware slicing ONLY for illustrated styles — they draw paper gutters and
            // unequal panels. Photoreal grids render edge-to-edge, where a bright sky band on
            // a thirds horizon (which the prompt now asks for!) would false-positive as a
            // gutter — so they always slice uniformly. Inset trims residual margins (3%).
            $style = (string) ($scene->image_style ?? $scene->lesson->image_style ?? 'realistic');
            $inset = (float) config('lessons.shot_grid_inset', 0.03);
            $cells = in_array($style, ['ink', 'etching', 'engraved', 'comic', 'sketched'], true)
                ? GridSlicer::sliceDetect($bytes, $rows, $cols, $inset)
                : GridSlicer::slice($bytes, $rows, $cols, $inset);

            // Two-pass engraving: model supplied figures/tone; we draw the hatching ourselves
            // (deterministic, sharp, exact paper colour). Renders at 2× cell size, so these
            // shots also skip the Upscayl pass below.
            if ($style === 'engraved') {
                $cells = array_map(fn (string $cell) => HatchEngraver::render($cell, 1024), $cells);
            }

            $baseDir = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/shots";
            foreach ($cells as $index => $cellBytes) {
                if (! isset($shots[$index])) {
                    break;
                }
                $path = "{$baseDir}/shot_{$index}.png";
                Storage::disk('public')->put($path, $cellBytes);
                $shots[$index]['image_path'] = $path;
            }
            $shots = array_values(array_filter($shots, fn (array $shot) => isset($shot['image_path'])));

            if ($shots === []) {
                throw new \RuntimeException('Grid slicing produced no usable shots.');
            }

            $scene->update([
                'shots' => $shots,
                'image_path' => $shots[0]['image_path'],   // fallback + card/cover compatibility
                'upscale_status' => null,
            ]);
            $this->maybeMarkReady($scene->fresh());

            // Cells are small (~512px) — upscale each so full-screen Ken Burns stays sharp.
            // Engraved shots skip this: HatchEngraver already rendered them at 2× with
            // drawn (not upscaled) lines — Real-ESRGAN would only soften them.
            if ($style !== 'engraved') {
                $hint = (string) ($scene->image_prompt ?? $scene->location ?? $scene->lesson->topic ?? '');
                foreach ($shots as $shot) {
                    UpscayleSceneImage::dispatchIfLocal($scene, $shot['image_path'], $style, $hint);
                }
            }
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Storyboard the scene. Shots whose anchor is not a verbatim substring of the script
     * are dropped (one LLM retry first) — a fake anchor would silently break shot sync.
     *
     * @return list<array{order: int, anchor_sentence: string, composition: string, description: string}>
     */
    private function storyboard(OpenAiLlmService $llm, Scene $scene, int $shotCount): array
    {
        $script = (string) $scene->script_segment;
        $best = [];

        foreach ([1, 2] as $attempt) {
            $result = $llm->json(
                system: ShotListPrompt::system($shotCount),
                user: ShotListPrompt::user($scene, $shotCount),
            );

            $valid = collect($result['shots'] ?? [])
                ->filter(fn ($shot) => is_array($shot)
                    && trim((string) ($shot['description'] ?? '')) !== ''
                    && self::anchorMatches($script, (string) ($shot['anchor_sentence'] ?? '')))
                ->map(fn (array $shot, int $index) => [
                    'order' => $index + 1,
                    'anchor_sentence' => (string) $shot['anchor_sentence'],
                    'composition' => (string) ($shot['composition'] ?? ''),
                    'description' => (string) $shot['description'],
                ])
                ->values()
                ->all();

            if (count($valid) > count($best)) {
                $best = $valid;
            }
            if (count($best) >= $shotCount) {
                return array_slice($best, 0, $shotCount);
            }

            Log::warning("GenerateSceneShots #{$scene->id}: attempt {$attempt} valid anchors ".count($valid)."/{$shotCount}");
        }

        // A partial storyboard (≥2 shots) still beats one static image.
        if (count($best) >= 2) {
            return $best;
        }

        throw new \RuntimeException('Storyboard failed: fewer than 2 shots with verbatim anchors.');
    }

    /** Whitespace-tolerant verbatim substring check (the LLM may fold line breaks). */
    public static function anchorMatches(string $script, string $anchor): bool
    {
        $normalize = fn (string $text) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? '');
        $anchor = $normalize($anchor);

        return $anchor !== '' && mb_strlen($anchor) >= 10 && str_contains($normalize($script), $anchor);
    }

    /**
     * One historical-accuracy validation per scene (not per shot). Failure is non-fatal —
     * the storyboard prompt already carries the brief's avoid-list.
     *
     * @return array<string, mixed>
     */
    private function validateVisuals(OpenAiLlmService $llm, FigureAppearanceService $appearance, Scene $scene): array
    {
        $brief = ShotListPrompt::briefFor($scene);
        $proposedVisuals = array_merge(
            (array) ($brief['visualEvidence'] ?? []),
            array_filter([$brief['image_prompt_seed'] ?? null]),
        );
        if ($proposedVisuals === []) {
            return [];
        }

        try {
            $figureAppearance = $appearance->describe(
                $scene->lesson->protagonist_qid,
                $scene->lesson->protagonist_name,
            );

            return $llm->json(
                system: HistoricalImageValidationPrompt::system(),
                user: HistoricalImageValidationPrompt::user($scene, $proposedVisuals, $figureAppearance),
            );
        } catch (Throwable $e) {
            Log::warning("GenerateSceneShots #{$scene->id}: validation failed, continuing without — {$e->getMessage()}");

            return [];
        }
    }
}
