<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\GameCardPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders the printable game pack (spelpakket) for a story-game lesson and stores it
 * as a single PDF on the public disk. Idempotent (re-render overwrites) and isolated:
 * a failure logs but never fails the lesson generation pipeline.
 */
class GenerateGamePack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $lessonId) {}

    public function handle(GameCardPdfService $pdf): void
    {
        $lesson = Lesson::find($this->lessonId);
        if (! $lesson) {
            return;
        }

        $path = "lessons/{$lesson->id}/game-pack.pdf";
        Storage::disk('public')->put($path, $pdf->pack($lesson));

        $lesson->update(['game_pack_path' => $path]);

        Log::info("GenerateGamePack #{$lesson->id}: game pack stored at {$path}");
    }

    public function failed(Throwable $e): void
    {
        // The game pack is an optional companion — never fail the lesson because of it.
        Log::error("GenerateGamePack #{$this->lessonId} failed — {$e->getMessage()}");
    }
}
