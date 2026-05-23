<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scene;
use App\Services\OpenAiImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Upscale a scene image in-place using the local Upscayl CLI.
 * Only dispatched in local environments (app.env === 'local').
 * Overwrites the stored file; path unchanged — callers re-fetch with a
 * busted ?v= cache-buster so the shader picks up the enhanced texture.
 */
class UpscayleSceneImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(
        public readonly int    $sceneId,
        public readonly string $imagePath,
        public readonly string $upscaylModel,
    ) {}

    public function handle(OpenAiImageService $imageService): void
    {
        $scene = Scene::findOrFail($this->sceneId);
        $scene->update(['upscale_status' => 'upscaling']);

        try {
            $original = Storage::disk('public')->get($this->imagePath);
            if (! is_string($original) || $original === '') {
                throw new \RuntimeException('Image not found on disk: ' . $this->imagePath);
            }

            $enhanced = $imageService->upscaleLocal($original, $this->upscaylModel);

            if ($enhanced === $original) {
                // upscayl-bin not available or failed — log and bail gracefully
                Log::warning('[UpscayleSceneImage] upscayl returned original bytes, skipping overwrite.', [
                    'sceneId' => $this->sceneId,
                    'model'   => $this->upscaylModel,
                ]);
                $scene->update(['upscale_status' => 'failed']);
                return;
            }

            Storage::disk('public')->put($this->imagePath, $enhanced);

            // Touch updated_at so scene:load gets a fresh ?v= cache-buster
            $scene->update(['upscale_status' => 'done']);
        } catch (Throwable $e) {
            Log::warning('[UpscayleSceneImage] failed: ' . $e->getMessage(), ['sceneId' => $this->sceneId]);
            $scene->update(['upscale_status' => 'failed']);
        }
    }

    /** Dispatch only when Upscayl is enabled and we're in the local environment. */
    public static function dispatchIfLocal(
        Scene  $scene,
        string $imagePath,
        string $style,
        string $hint = '',
    ): void {
        if (! app()->isLocal()) {
            return;
        }

        if (! (bool) config('services.upscayl.enabled', true)) {
            return;
        }

        $model = OpenAiImageService::selectUpscaylModel($style, $hint);

        static::dispatch($scene->id, $imagePath, $model);
    }
}
