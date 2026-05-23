<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Jobs\UpscayleSceneImage;
use App\Models\Scene;
use App\Services\FalAiImageService;
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
    use Dispatchable, Batchable, InteractsWithQueue, Queueable, SerializesModels, MarksSceneReady;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiImageService $image, OpenAiLlmService $llm, FalAiImageService $fal): void
    {
        $scene = Scene::with('lesson.source')->findOrFail($this->sceneId);

        try {
            $style       = (string) ($scene->image_style ?? $scene->lesson->image_style ?? 'realistic');
            $destination = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/skybox.png";

            $prompt = $this->buildPrompt($scene, $style, $llm);

            $bytes = $fal->txt2img(
                prompt: $prompt,
                width:  (int) config('services.falai.scene_width', 1024),
                height: (int) config('services.falai.scene_height', 512),
                steps:  (int) config('services.falai.steps', 4),
            );
            $bytes = $image->cleanPanorama($bytes);
            Storage::disk('public')->put($destination, $bytes);

            $path = $destination;

            $scene->update(['image_path' => $path, 'upscale_status' => null]);
            $this->maybeMarkReady($scene->fresh());

            UpscayleSceneImage::dispatchIfLocal(
                scene:     $scene,
                imagePath: $path,
                style:     $style,
                hint:      (string) ($scene->image_prompt ?? $scene->location ?? $scene->lesson->topic ?? ''),
            );

            if ((bool) config('services.worldlabs.enabled', false)) {
                GenerateWorldLabsScene::dispatch($this->sceneId);
            }
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function buildPrompt(Scene $scene, string $style, OpenAiLlmService $llm): string
    {
        // Teacher-supplied prompt: still run validation to add the negative block,
        // but skip the LLM round-trip and use the teacher's text as the scene description.
        if ($scene->image_prompt) {
            $proposedVisuals = [$scene->image_prompt];
        } else {
            $brief           = $scene->lesson->outline['scene_briefs'][$scene->order - 1] ?? [];
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
        try {
            $validation = $llm->json(
                system: HistoricalImageValidationPrompt::system(),
                user:   HistoricalImageValidationPrompt::user($scene, $proposedVisuals),
            );
        } catch (Throwable $e) {
            // Validation failure is non-fatal — fall back to unvalidated prompt.
            Log::warning('[GenerateSceneImage] validation failed, using fallback: ' . $e->getMessage());
            $seed = $scene->image_prompt
                ?? ($scene->lesson->outline['scene_briefs'][$scene->order - 1]['image_prompt_seed'] ?? null)
                ?? $scene->location
                ?? $scene->lesson->topic;
            return ImageStyleTemplate::build($seed, $style, $scene->kind === 'game');
        }

        return ImageStyleTemplate::buildFromValidated($validation, $style, $scene->kind === 'game');
    }
}
