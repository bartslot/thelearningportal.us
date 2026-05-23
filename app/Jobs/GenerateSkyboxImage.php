<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scene;
use App\Services\FalAiImageService;
use App\Services\HistoricalImageValidationPrompt;
use App\Services\OpenAiImageService;
use App\Services\OpenAiLlmService;
use App\Services\Support\ImageStyleTemplate;
use App\Jobs\UpscayleSceneImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateSkyboxImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 150;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(OpenAiLlmService $llm, OpenAiImageService $imageService, FalAiImageService $fal): void
    {
        $scene = Scene::with('lesson')->findOrFail($this->sceneId);

        if (! $scene->image_path) {
            throw new \RuntimeException('Generate the flat image first before converting to skybox.');
        }

        try {
            $style  = (string) ($scene->image_style ?? $scene->lesson->image_style ?? 'realistic');
            $prompt = $this->buildSkyboxPrompt($scene, $style, $llm);

            $bytes = $fal->txt2img(
                prompt: $prompt,
                width:  (int) config('services.falai.skybox_width', 1024),
                height: (int) config('services.falai.skybox_height', 512),
                steps:  (int) config('services.falai.steps', 4),
            );

            $destination = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/skybox_panorama.png";
            $cleaned     = $imageService->cleanPanorama($bytes);
            Storage::disk('public')->put($destination, $cleaned !== '' ? $cleaned : $bytes);

            $scene->update([
                'skybox_image_path' => $destination,
                'status'            => 'ready',
                'error_message'     => null,
                'upscale_status'    => null,
            ]);

            UpscayleSceneImage::dispatchIfLocal(
                scene:     $scene,
                imagePath: $destination,
                style:     $style,
                hint:      (string) ($scene->image_prompt ?? $scene->location ?? $scene->lesson->topic ?? ''),
            );
        } catch (Throwable $e) {
            Log::error('[GenerateSkyboxImage] ' . $e->getMessage(), ['sceneId' => $this->sceneId]);
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function buildSkyboxPrompt(Scene $scene, string $style, OpenAiLlmService $llm): string
    {
        $brief           = $scene->lesson->outline['scene_briefs'][$scene->order - 1] ?? [];
        $proposedVisuals = array_merge(
            $brief['visualEvidence'] ?? [],
            array_filter([$scene->image_prompt ?? $brief['image_prompt_seed'] ?? null]),
        );

        if (empty($proposedVisuals)) {
            $seed = $scene->location ?? $scene->lesson->topic;
            return ImageStyleTemplate::buildSkybox($seed, $style);
        }

        try {
            $validation = $llm->json(
                system: HistoricalImageValidationPrompt::system(),
                user:   HistoricalImageValidationPrompt::user($scene, $proposedVisuals),
            );
            return ImageStyleTemplate::buildSkyboxFromValidated($validation, $style);
        } catch (Throwable $e) {
            Log::warning('[GenerateSkyboxImage] validation failed, using fallback: ' . $e->getMessage());
            $seed = $scene->image_prompt ?? ($brief['image_prompt_seed'] ?? null) ?? $scene->location ?? $scene->lesson->topic;
            return ImageStyleTemplate::buildSkybox($seed, $style);
        }
    }

}
