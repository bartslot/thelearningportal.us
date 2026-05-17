<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Models\Scene;
use App\Services\OpenAiImageService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(OpenAiImageService $image): void
    {
        $scene = Scene::with('lesson')->findOrFail($this->sceneId);

        try {
            $ext         = (string) config('services.openai.image_format', 'webp');
            $destination = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/skybox.{$ext}";
            $path = $image->generate(
                seedPrompt:  (string) ($scene->image_prompt ?? $scene->location ?? $scene->lesson->topic),
                style:       (string) ($scene->image_style ?? $scene->lesson->image_style ?? 'realistic'),
                destination: $destination,
                isGame:      $scene->kind === 'game',
            );
            $scene->update(['image_path' => $path]);
            $this->maybeMarkReady($scene->fresh());
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }
}
