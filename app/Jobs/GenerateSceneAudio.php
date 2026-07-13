<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Models\Scene;
use App\Services\Support\NarrationVoice;
use App\Services\TtsService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateSceneAudio implements ShouldQueue
{
    use Dispatchable, Batchable, InteractsWithQueue, Queueable, SerializesModels, MarksSceneReady;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $sceneId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(TtsService $tts): void
    {
        $scene = Scene::with('lesson.avatar')->findOrFail($this->sceneId);

        try {
            $avatar  = $scene->lesson->avatar;
            $script  = (string) ($scene->script_segment ?? '');
            $text    = $tts->prepareSpeechText($script);

            // Temporary global override (e.g. ElevenLabs → Azure backup) wins over the avatar's
            // provider. A non-ElevenLabs backup can't use the avatar's ElevenLabs voice_id, so the
            // voice follows the LESSON'S CONTENT LANGUAGE (teacher locale — the same signal that
            // makes the script prompt write Dutch): native narrator per language, multilingual
            // fallback otherwise. TTS_PROVIDER_OVERRIDE_VOICE, when set, pins one voice globally.
            $override = (string) config('services.tts.provider_override', '');
            $provider = $override !== '' ? $override : ($avatar?->voice_provider ?? 'elevenlabs');
            $voiceId  = ($override !== '' && $override !== 'elevenlabs')
                ? NarrationVoice::azure(
                    $scene->lesson->teacher?->locale,
                    (string) config('services.tts.provider_override_voice', ''),
                )
                // Avatar-driven: the studio's per-language preferred voice (voice_map)
                // wins for the lesson's language; falls back to the avatar's base voice.
                : ($avatar?->voiceFor($scene->lesson->teacher?->locale) ?? '');

            $timing  = null;
            $audio   = $tts->generateAudioRaw(
                $text,
                $voiceId,
                (float) ($avatar?->voice_speed ?? 1.0),
                $provider,
                $timing,
            );
            if ($audio === null) {
                throw new \RuntimeException('TTS service returned no audio.');
            }

            $ext  = $tts->lastExtension();
            $path = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/narration.{$ext}";
            Storage::disk('public')->put($path, $audio);

            $scene->update([
                'audio_path'        => $path,
                'audio_alignment'   => $timing['character_timings'] ?? [],
                'audio_script_hash' => sha1($script),
            ]);

            $this->maybeMarkReady($scene->fresh());
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }
}
