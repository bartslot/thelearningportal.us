<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Models\Scene;
use App\Services\Support\NarrationVoice;
use App\Services\Support\ScriptLanguage;
use App\Services\Support\PronunciationLexicon;
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
            // Spoken-form fixes only ("limes" → "liemes" for Dutch voices) — the on-screen
            // script keeps the correct written form.
            $text    = PronunciationLexicon::apply($text, $scene->lesson->teacher?->locale);

            // Temporary global override (e.g. ElevenLabs → Azure backup) wins over the avatar's
            // provider. A non-ElevenLabs backup can't use the avatar's ElevenLabs voice_id, so the
            // voice follows the lesson's CONTENT language (see the precedence below): native
            // narrator per language, multilingual fallback otherwise. TTS_PROVIDER_OVERRIDE_VOICE,
            // when set, pins one voice globally.
            $override = (string) config('services.tts.provider_override', '');
            $provider = $override !== '' ? $override : ($avatar?->voice_provider ?? 'elevenlabs');
            // For NARRATION the script itself is the authority: these are the actual words being
            // read aloud, and an English sentence read by a Dutch voice is wrong no matter what the
            // teacher's settings say. The teaching language (and then the interface locale) is only
            // the fallback for text too short or ambiguous to call.
            //
            // Note this is the opposite priority to LessonScriptPrompt::contentLanguage, which asks
            // a different question: what language should we WRITE the next lesson in. That one
            // rightly follows the teacher's teaching language.
            $teacher  = $scene->lesson->teacher;
            $locale   = ScriptLanguage::detect($text, $teacher?->teachingLocale() ?? 'en');
            $voiceId  = match (true) {
                // Self-hosted Piper: pick the voice by language (Dutch pim, English ryan) so an
                // English lesson isn't narrated in a Dutch accent.
                $provider === 'piper' => NarrationVoice::piper($locale),
                $override !== '' && $override !== 'elevenlabs' => NarrationVoice::azure(
                    $locale, (string) config('services.tts.provider_override_voice', ''),
                ),
                // Avatar-driven: the studio's per-language preferred voice (voice_map)
                // wins for the lesson's language; falls back to the avatar's base voice.
                default => $avatar?->voiceFor($locale) ?? '',
            };

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

            // Duration for the wizard timeline + pacing. Providers with word/character timings
            // (ElevenLabs, edge-tts) give an exact end; Azure returns audio only, so estimate from
            // the spoken word count (~2.6 words/sec, adjusted for the voice speed). Without this a
            // narration scene has duration_seconds=null → the Step-5 preview timeline is 0:00 and
            // its Play button does nothing.
            $alignment = $timing['character_timings'] ?? [];
            $duration = 0.0;
            if (! empty($alignment)) {
                $last = end($alignment);
                $duration = (float) ($last['end'] ?? $last['time'] ?? $last['endTime'] ?? 0);
            }
            if ($duration <= 0.0) {
                $words = max(1, str_word_count(strip_tags($text)));
                $speed = (float) ($avatar?->voice_speed ?? 1.0) ?: 1.0;
                $duration = round($words / (2.6 * $speed), 1);
            }
            $duration = max(3.0, $duration);

            $scene->update([
                'audio_path'        => $path,
                'audio_alignment'   => $alignment,
                'audio_script_hash' => sha1($script),
                // The language this audio is actually IN, so nothing downstream has to infer it.
                'audio_locale'      => $locale,
                'duration_seconds'  => (int) ceil($duration),
            ]);

            $this->maybeMarkReady($scene->fresh());
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }
}
