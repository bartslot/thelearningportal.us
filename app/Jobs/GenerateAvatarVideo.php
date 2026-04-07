<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Services\AvatarService;
use App\Services\RhubarbService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Re-runs only the avatar video step for a lesson that already has audio.
 * Triggered when a teacher clicks the retry button on the "Render avatar video" step.
 */
class GenerateAvatarVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // avatar rendering can take 5–10 min
    public int $tries   = 1;

    public function __construct(
        private readonly int $lessonId
    ) {}

    public function handle(AvatarService $avatarService, RhubarbService $rhubarbService): void
    {
        $lesson = Lesson::with('avatar')->findOrFail($this->lessonId);

        Log::info("GenerateAvatarVideo: starting for lesson #{$lesson->id} — {$lesson->title}");

        // Always restore Ready on the way out, regardless of outcome
        try {
            $this->attemptVideoGeneration($lesson, $avatarService, $rhubarbService);
        } finally {
            // Guard: if anything left the lesson in Generating, snap it back to Ready
            $lesson->refresh();
            if ($lesson->status === LessonStatus::Generating) {
                $lesson->update(['status' => LessonStatus::Ready]);
            }
        }
    }

    private function attemptVideoGeneration(Lesson $lesson, AvatarService $avatarService, RhubarbService $rhubarbService): void
    {
        if (! $lesson->audio_path) {
            Log::info("GenerateAvatarVideo #{$lesson->id}: no audio — cannot generate video");
            return;
        }

        // Generate visemes for lip-sync player (fast, CPU-only)
        if (! $lesson->visemes_path) {
            $visemesPath = $rhubarbService->generateVisemes($lesson->audio_path, (string) $lesson->id);
            if ($visemesPath) {
                $lesson->update(['visemes_path' => $visemesPath]);
                Log::info("GenerateAvatarVideo #{$lesson->id}: visemes generated at {$visemesPath}");
            }
        }

        $avatar      = $lesson->avatar;
        $portraitSrc = $avatar?->portrait_path
            ? public_path($avatar->portrait_path)
            : null;

        if (! $portraitSrc || ! file_exists($portraitSrc)) {
            Log::info("GenerateAvatarVideo #{$lesson->id}: no portrait on avatar — video skipped");
            return;
        }

        // Copy portrait into lesson storage (same convention as GenerateLesson)
        $lessonPortraitPath = "lessons/{$lesson->id}/portrait.jpg";
        Storage::disk('public')->put($lessonPortraitPath, file_get_contents($portraitSrc));

        $videoPath = $avatarService->generateVideo(
            audioPath:    $lesson->audio_path,
            portraitPath: $lessonPortraitPath,
            lessonId:     (string) $lesson->id,
        );

        if ($videoPath) {
            $lesson->update([
                'video_path' => $videoPath,
                'status'     => LessonStatus::Ready,
            ]);
            Log::info("GenerateAvatarVideo #{$lesson->id}: complete — {$videoPath}");
        } else {
            // Null means no provider available or unavailable — not a job failure
            Log::info("GenerateAvatarVideo #{$lesson->id}: video skipped (provider unavailable or not configured)");
            $lesson->update(['status' => LessonStatus::Ready]);
        }
    }
}
