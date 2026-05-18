<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scene;
use App\Services\WorldLabsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateWorldLabsScene implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 720; // WorldLabs can take up to ~10 min

    public function __construct(public readonly int $sceneId) {}

    public function handle(): void
    {
        $apiKey = (string) config('services.worldlabs.api_key', '');
        if ($apiKey === '') {
            return; // silently skip if not configured
        }

        $scene = Scene::with('lesson')->findOrFail($this->sceneId);

        if (! $scene->image_path) {
            return; // need the source image first
        }

        $scene->update(['world_labs_status' => 'pending']);

        try {
            $service  = new WorldLabsService($apiKey, maxPollSeconds: 660);

            // WorldLabs needs a publicly reachable URL. Upload to fal storage
            // so it works in local dev (where 127.0.0.1 isn't reachable externally).
            $imageBytes = Storage::disk('public')->get($scene->image_path);
            $imageUrl   = $service->uploadImageToFal($imageBytes, $scene->image_path);

            $prefix = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/world";
            $name     = "scene-{$scene->id}";
            $prompt   = $scene->image_prompt ?? $scene->location ?? $scene->lesson->topic ?? null;

            $scene->update(['world_labs_status' => 'generating']);

            $assets = $service->generate($imageUrl, $name, $prompt);
            $paths  = $service->downloadAssets($assets, $prefix);

            $scene->update([
                'world_labs_status'       => 'ready',
                'world_labs_operation_id' => $assets['operation_id'],
                'world_semantics'         => $assets['semantics'],
                ...$paths,
            ]);
        } catch (Throwable $e) {
            $scene->update(['world_labs_status' => 'failed']);
            throw $e;
        }
    }
}
