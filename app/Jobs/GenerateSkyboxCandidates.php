<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scene;
use App\Services\OpenAiImageService;
use App\Services\OpenAiLlmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generate N candidate equirectangular panoramas for a scene (the standard 3D-gen flow:
 * generate options → teacher picks one → the picked one becomes skybox_image_path).
 *
 * The prompt is built ONCE via GenerateSkyboxImage::buildSkyboxPromptFor() and reused for
 * every candidate, so candidates are stylistic variations of the same intent. No Upscayl
 * here — candidates are throwaway previews; enhancement happens after one is chosen.
 */
class GenerateSkyboxCandidates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /** How many candidate panoramas to generate per request. */
    private const CANDIDATE_COUNT = 4;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(OpenAiLlmService $llm, OpenAiImageService $imageService): void
    {
        $scene = Scene::with('lesson')->findOrFail($this->sceneId);

        if (! $scene->image_path) {
            throw new \RuntimeException('Generate the flat image first before generating panorama options.');
        }

        $style = (string) ($scene->image_style ?? $scene->lesson->image_style ?? 'realistic');
        $prompt = GenerateSkyboxImage::buildSkyboxPromptFor($scene, $style, $llm);
        $size = (string) config('services.openai.skybox_size', config('services.openai.image_size', '1536x1024'));

        $paths = [];

        for ($i = 0; $i < self::CANDIDATE_COUNT; $i++) {
            try {
                $bytes = $imageService->generatePanoramaBytesFromPrompt(
                    prompt: $prompt,
                    size: $size,
                );

                $destination = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/skybox_cand_{$i}.png";
                Storage::disk('public')->put($destination, $bytes);
                $paths[] = $destination;
            } catch (Throwable $e) {
                // One candidate failing must not abort the rest — keep the successes.
                Log::warning('[GenerateSkyboxCandidates] candidate '.$i.' failed: '.$e->getMessage(), [
                    'sceneId' => $this->sceneId,
                ]);
            }
        }

        if (empty($paths)) {
            $message = 'All panorama options failed to generate. Please try again.';
            Log::error('[GenerateSkyboxCandidates] '.$message, ['sceneId' => $this->sceneId]);
            $scene->update(['status' => 'failed', 'error_message' => $message]);

            throw new \RuntimeException($message);
        }

        $scene->update([
            'skybox_candidates' => $paths,
            'skybox_image_path' => null,
            'status' => 'ready',
            'error_message' => null,
        ]);
    }
}
