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

class EnhanceSkyboxImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(OpenAiImageService $image): void
    {
        $scene = Scene::findOrFail($this->sceneId);

        $path = $scene->skybox_image_path ?? $scene->image_path;
        if (! $path) {
            return;
        }

        $scene->update(['upscale_status' => 'upscaling']);

        try {
            $original = Storage::disk('public')->get($path);
            if (! is_string($original) || $original === '') {
                throw new \RuntimeException('Skybox image not found on disk: '.$path);
            }

            $enhanced = $image->enhance($original);

            if ($enhanced === $original) {
                Log::warning('[EnhanceSkyboxImage] upscayl returned original bytes; keeping existing skybox.', [
                    'sceneId' => $this->sceneId,
                    'path' => $path,
                ]);
                $scene->update(['status' => 'ready', 'upscale_status' => 'failed', 'error_message' => null]);

                return;
            }

            Storage::disk('public')->put($path, $enhanced);

            // Image overwritten in-place; path unchanged. Touch updated_at via upscale_status
            // so the inspector poll re-fires scene:load with a fresh ?v= cache-buster.
            $scene->update(['status' => 'ready', 'upscale_status' => 'done']);
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'upscale_status' => 'failed', 'error_message' => $e->getMessage()]);
            report($e);
        }
    }
}
