<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SpriteStatus;
use App\Models\Avatar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessAvatarPortrait implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $avatarId) {}

    public function handle(): void
    {
        $avatar = Avatar::findOrFail($this->avatarId);
        $avatar->update(['sprite_status' => SpriteStatus::Processing]);

        $portraitPath = $avatar->portrait_path;

        if (! $portraitPath || ! Storage::disk('public')->exists($portraitPath)) {
            Log::error("ProcessAvatarPortrait: portrait not found for avatar {$this->avatarId}");
            $avatar->update(['sprite_status' => SpriteStatus::Failed]);
            return;
        }

        $imageBytes  = Storage::disk('public')->get($portraitPath);
        $base64      = base64_encode($imageBytes);
        $vercelUrl   = rtrim(config('services.vercel_functions.url'), '/');

        $response = Http::timeout(30)
            ->post("{$vercelUrl}/api/detect-landmarks", [
                'portrait_base64' => $base64,
            ]);

        if (! $response->successful()) {
            Log::error("ProcessAvatarPortrait: detect-landmarks failed", [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
            $avatar->update(['sprite_status' => SpriteStatus::Failed]);
            return;
        }

        $data      = $response->json();
        $landmarks = $data['landmarks'];
        $baseDir   = "avatars/{$this->avatarId}/sprites";

        foreach ($data['mouth_frames'] as $i => $frame) {
            $this->storeBase64($frame, "{$baseDir}/mouth_{$i}.png");
        }

        foreach (['left_open', 'left_closed', 'right_open', 'right_closed'] as $key) {
            $this->storeBase64($data['eye_frames'][$key], "{$baseDir}/eye_{$key}.png");
        }

        $avatar->update([
            'landmarks_json' => $landmarks,
            'sprite_status'  => SpriteStatus::Ready,
        ]);

        Log::info("ProcessAvatarPortrait: sprites ready for avatar {$this->avatarId}");
    }

    private function storeBase64(string $dataUrl, string $storagePath): void
    {
        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $dataUrl);
        Storage::disk('public')->put($storagePath, base64_decode($base64));
    }
}
