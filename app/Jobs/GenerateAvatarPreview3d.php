<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Avatar;
use App\Models\Lesson;
use App\Services\AzureTtsService;
use App\Services\SsmlBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class GenerateAvatarPreview3d implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $retryAfter = 60; // seconds — handles Azure 429 rate-limit

    public function __construct(
        public readonly int $avatarId,
        public readonly string $script,
        public readonly ?int $lessonId = null,
    ) {}

    public function handle(SsmlBuilder $builder, AzureTtsService $azure): void
    {
        $avatar = Avatar::findOrFail($this->avatarId);
        $ssml   = $builder->build($this->script, $avatar);

        $prefix = $this->lessonId
            ? "lessons/{$this->lessonId}"
            : "previews/{$this->avatarId}";

        $paths = $azure->synthesize($ssml, $prefix);

        if ($this->lessonId) {
            Lesson::where('id', $this->lessonId)->update([
                'audio_3d_path'    => $paths['audio_3d_path'],
                'blendshapes_path' => $paths['blendshapes_path'],
            ]);
        }

        Cache::put("avatar3d_preview_{$this->avatarId}", [
            'status'          => 'ready',
            'audio_url'       => Storage::url($paths['audio_3d_path']),
            'blendshapes_url' => Storage::url($paths['blendshapes_path']),
        ], now()->addMinutes(30));
    }

    public function failed(\Throwable $e): void
    {
        Cache::put("avatar3d_preview_{$this->avatarId}", [
            'status' => 'failed',
            'error'  => $e->getMessage(),
        ], now()->addMinutes(10));
    }
}
