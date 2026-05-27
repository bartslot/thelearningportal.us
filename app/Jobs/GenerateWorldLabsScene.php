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
        // Skip gracefully when WorldLabs is disabled or not configured
        if (! config('services.worldlabs.enabled', false)) {
            Scene::find($this->sceneId)?->update(['world_labs_status' => 'skipped']);
            return;
        }

        $apiKey = (string) config('services.worldlabs.api_key', '');
        if ($apiKey === '') {
            Scene::find($this->sceneId)?->update(['world_labs_status' => 'skipped']);
            \Illuminate\Support\Facades\Log::warning('[WorldLabs] WORLD_LABS_API_KEY not configured — skipping scene ' . $this->sceneId);
            return;
        }

        $scene = Scene::with('lesson')->findOrFail($this->sceneId);

        if (! $scene->image_path) {
            $scene->update(['world_labs_status' => 'failed']);
            \Illuminate\Support\Facades\Log::error('[WorldLabs] scene ' . $this->sceneId . ' has no image_path — generate the skybox image first');
            return;
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
            $msg = $e->getMessage();
            // 402 = out of credits, 500 = fal/WorldLabs service error — skip, don't crash pipeline
            if (str_contains($msg, '402') || str_contains($msg, 'Insufficient credits') || str_contains($msg, '500 Internal Server Error') || str_contains($msg, 'fal storage upload failed')) {
                \Illuminate\Support\Facades\Log::warning('[WorldLabs] skipping scene ' . $this->sceneId . ': ' . $msg);
                $scene->update(['world_labs_status' => 'skipped']);
                return; // don't re-queue
            }
            $scene->update(['world_labs_status' => 'failed']);
            throw $e;
        }
    }
}
