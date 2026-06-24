<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Models\Scene;
use App\Services\FigureAppearanceService;
use App\Services\HistoricalImageValidationPrompt;
use App\Services\OpenAiImageService;
use App\Services\OpenAiLlmService;
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

class GenerateSceneImage implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, MarksSceneReady, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiImageService $image, OpenAiLlmService $llm, FigureAppearanceService $appearance): void
    {
        $scene = Scene::with('lesson.source')->findOrFail($this->sceneId);

        try {
            $style = (string) ($scene->image_style ?? $scene->lesson->image_style ?? 'realistic');
            $destination = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/skybox.png";

            $prompt = $this->buildPrompt($scene, $style, $llm, $appearance);

            $bytes = $image->generatePanoramaBytesFromPrompt(
                prompt: $prompt,
                size: (string) config('services.openai.scene_size', config('services.openai.image_size', '1536x1024')),
            );

            Storage::disk('public')->put($destination, $bytes);

            $path = $destination;

            $scene->update(['image_path' => $path, 'upscale_status' => null]);
            $this->maybeMarkReady($scene->fresh());

            UpscayleSceneImage::dispatchIfLocal(
                scene: $scene,
                imagePath: $path,
                style: $style,
                hint: (string) ($scene->image_prompt ?? $scene->location ?? $scene->lesson->topic ?? ''),
            );

            if ((bool) config('services.worldlabs.enabled', false)) {
                GenerateWorldLabsScene::dispatch($this->sceneId);
            }
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function buildPrompt(Scene $scene, string $style, OpenAiLlmService $llm, FigureAppearanceService $appearance): string
    {
        // Teacher-supplied prompt: still run validation to add the negative block,
        // but skip the LLM round-trip and use the teacher's text as the scene description.
        if ($scene->image_prompt) {
            $proposedVisuals = [$scene->image_prompt];
        } else {
            $brief = $scene->lesson->outline['scene_briefs'][$scene->order - 1] ?? [];
            $proposedVisuals = array_merge(
                $brief['visualEvidence'] ?? [],
                array_filter([$brief['image_prompt_seed'] ?? null]),
            );

            if (empty($proposedVisuals)) {
                // No brief data — fall back gracefully without a validation call.
                $seed = $scene->location ?? $scene->lesson->topic;

                return ImageStyleTemplate::build($seed, $style, $scene->kind === 'game');
            }
        }

        // ── Historical accuracy validation ────────────────────────────────────
        // Ground the protagonist's look in a real reference portrait (cached per figure) so the
        // validator renders them accurately instead of inventing anachronisms (e.g. a modern cap).
        $figureAppearance = $appearance->describe(
            $scene->lesson->protagonist_qid,
            $scene->lesson->protagonist_name,
        );

        try {
            $validation = $llm->json(
                system: HistoricalImageValidationPrompt::system(),
                user: HistoricalImageValidationPrompt::user($scene, $proposedVisuals, $figureAppearance),
            );
        } catch (Throwable $e) {
            // Validation failure is non-fatal — fall back to unvalidated prompt.
            Log::warning('[GenerateSceneImage] validation failed, using fallback: '.$e->getMessage());
            $seed = $scene->image_prompt
                ?? ($scene->lesson->outline['scene_briefs'][$scene->order - 1]['image_prompt_seed'] ?? null)
                ?? $scene->location
                ?? $scene->lesson->topic;

            return ImageStyleTemplate::build($seed, $style, $scene->kind === 'game');
        }

        return ImageStyleTemplate::buildFromValidated($validation, $style, $scene->kind === 'game');
    }
}
