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

    public int $timeout = 300;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiImageService $image, OpenAiLlmService $llm, FigureAppearanceService $appearance): void
    {
        $scene = Scene::with('lesson.source')->findOrFail($this->sceneId);
        if ($scene->hasManualBackground()) {
            return;
        }

        // Real historical imagery is the default. Generating a background costs money per scene and
        // invents a picture of something that actually happened; a sourced painting comes with an
        // artist, a date and a credit. Only fall through to the image model when someone has
        // explicitly switched it on (AI_IMAGE_GENERATION=true).
        if (! config('services.imagery.ai_generation', false)) {
            $this->sourceRealImagery($scene);

            return;
        }

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

            $scene->refresh();
            if ($scene->hasManualBackground()) {
                return;
            }
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
            if (! $scene->fresh()->hasManualBackground()) {
                $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    /**
     * Attach a real painting or photograph instead of generating one.
     *
     * Searches our paintings corpus first and Wikimedia Commons second (SceneImageSourcer decides
     * the order from the scene's year — pre-photography subjects are far better served by art).
     * The query is built from what the scene is actually about; a scene left without an image is
     * still marked ready, because a missing background must never strand a lesson mid-build.
     */
    private function sourceRealImagery(Scene $scene): void
    {
        /** @var \App\Services\SceneImageSourcer $sourcer */
        $sourcer = app(\App\Services\SceneImageSourcer::class);

        $year = is_numeric($scene->year) ? (int) $scene->year : null;
        $queries = array_values(array_filter([
            trim((string) $scene->location.' '.(string) $scene->chapter_name),
            (string) $scene->location,
            (string) $scene->chapter_name,
            (string) ($scene->lesson->topic ?? ''),
        ], fn ($q) => mb_strlen(trim((string) $q)) >= 3));

        foreach ($queries as $query) {
            $hit = $sourcer->find($query, $year, 'auto');
            if (! $hit) {
                continue;
            }
            $path = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/bg.jpg";
            if ($sourcer->download($hit['url'], $path) === null) {
                continue;
            }
            $config = $scene->config ?? [];
            $config['image_credit'] = $hit['credit'];
            // Remember where it came from so `lessons:enhance-images` can re-fetch a bigger original.
            $config['image_source_url'] = $hit['url'];
            // Crop anchor — the key the PLAYER reads. A portrait cropped to 16:9 from the centre
            // loses the face, which is the whole reason the sourcer works out a focus at all.
            $config['background_focus'] = (string) ($hit['focus'] ?? 'center');
            $scene->update(['image_path' => $path, 'config' => $config, 'upscale_status' => null]);
            $this->maybeMarkReady($scene->fresh());

            return;
        }

        Log::info('GenerateSceneImage: no real imagery found', ['scene' => $scene->id, 'tried' => $queries]);
        $this->maybeMarkReady($scene->fresh());
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
