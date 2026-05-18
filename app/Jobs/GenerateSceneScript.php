<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Models\Scene;
use App\Services\OpenAiLlmService;
use App\Services\SceneScriptPrompt;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSceneScript implements ShouldQueue
{
    use Dispatchable, Batchable, InteractsWithQueue, Queueable, SerializesModels, MarksSceneReady;

    public int $tries   = 3;
    public int $timeout = 90;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $scene = Scene::with('lesson.source')->findOrFail($this->sceneId);
        $scene->update(['status' => 'generating']);

        try {
            $text = $llm->text(
                system: SceneScriptPrompt::system(),
                user:   SceneScriptPrompt::user(
                    $scene,
                    (string) ($scene->lesson->source?->extracted_text ?? ''),
                    (string) ($scene->lesson->tone ?? ''),
                    (string) ($scene->lesson->grade_level ?? '9th grade'),
                ),
            );

            $scene->update(['script_segment' => trim($text)]);
            $this->maybeMarkReady($scene->fresh());
        } catch (Throwable $e) {
            $scene->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
