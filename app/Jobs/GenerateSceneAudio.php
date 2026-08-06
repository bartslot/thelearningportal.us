<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\MarksSceneReady;
use App\Models\Scene;
use App\Services\Support\NarrationTiming;
use App\Services\Support\NarrationVoice;
use App\Services\Support\PronunciationLexicon;
use App\Services\Support\ScriptLanguage;
use App\Services\TtsService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateSceneAudio implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, MarksSceneReady, Queueable, SerializesModels;

    public int $tries = 3;

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
            $avatar = $scene->lesson->avatar;
            $script = (string) ($scene->script_segment ?? '');
            $text = $tts->prepareSpeechText($script);
            // Spoken-form fixes only ("limes" → "liemes" for Dutch voices) — the on-screen
            // script keeps the correct written form.
            $text = PronunciationLexicon::apply($text, $scene->lesson->teacher?->locale);

            // Temporary global override (e.g. ElevenLabs → Azure backup) wins over the avatar's
            // provider. A non-ElevenLabs backup can't use the avatar's ElevenLabs voice_id, so the
            // voice follows the lesson's CONTENT language (see the precedence below): native
            // narrator per language, multilingual fallback otherwise. TTS_PROVIDER_OVERRIDE_VOICE,
            // when set, pins one voice globally.
            $override = (string) config('services.tts.provider_override', '');
            $provider = $override !== '' ? $override : ($avatar?->voice_provider ?? 'elevenlabs');

            // A demo guest never spends ElevenLabs credits, whatever narrator the lesson names.
            //
            // /try hands an anonymous visitor a throwaway teacher account and a copy of the demo
            // lesson in the real wizard. Re-narrating a scene there is one click, the account costs
            // nothing to create, and there is no rate limit that would stop somebody doing it a
            // thousand times. Azure is a fraction of the price and the demo still speaks.
            //
            // This does NOT silence the demo lesson: its narration is generated ahead of time by
            // `lessons:compose` under a real teacher, so what a visitor hears is already the
            // ElevenLabs recording. Only NEW audio a guest asks for is downgraded.
            if ($provider === 'elevenlabs' && $scene->lesson->teacher?->isGuestDemo()) {
                $provider = 'azure';
            }
            // For NARRATION the script itself is the authority: these are the actual words being
            // read aloud, and an English sentence read by a Dutch voice is wrong no matter what the
            // teacher's settings say. The teaching language (and then the interface locale) is only
            // the fallback for text too short or ambiguous to call.
            //
            // Note this is the opposite priority to LessonScriptPrompt::contentLanguage, which asks
            // a different question: what language should we WRITE the next lesson in. That one
            // rightly follows the teacher's teaching language.
            $teacher = $scene->lesson->teacher;
            $locale = ScriptLanguage::detect($text, $teacher?->teachingLocale() ?? 'en');
            $voiceId = match (true) {
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

            // A lesson with no narrator at all resolves to an empty voice id. Sent down the chain
            // that way it used to reach tryAzure's old en-US default, so a FRENCH lesson came back
            // read by an American voice. There is no ElevenLabs voice to use without a narrator,
            // so route it to Azure deliberately, on the native voice for the language it is
            // actually written in.
            if ($voiceId === '') {
                $provider = 'azure';
                $voiceId = NarrationVoice::azure($locale);
                Log::info("[Narration] scene {$scene->id}: lesson names no narrator; using the native {$locale} voice {$voiceId}.");
            }

            $timing = null;
            $audio = $tts->generateAudioRaw(
                $text,
                $voiceId,
                (float) ($avatar?->voice_speed ?? 1.0),
                $provider,
                $timing,
            );
            if ($audio === null) {
                throw new \RuntimeException('TTS service returned no audio.');
            }

            $ext = $tts->lastExtension();
            $path = "lessons/{$scene->lesson_id}/scenes/{$scene->id}/narration.{$ext}";
            Storage::disk('public')->put($path, $audio);

            // Duration for the wizard timeline + pacing. ElevenLabs returns character timings and
            // therefore an exact end; Azure and the rest return audio only, so estimate from the
            // spoken word count (~2.6 words/sec, adjusted for the voice speed). Without this a
            // narration scene has duration_seconds=null → the Step-5 preview timeline is 0:00 and
            // its Play button does nothing.
            $narrationTiming = NarrationTiming::fromStored($timing['character_timings'] ?? []);
            $duration = $narrationTiming->duration();
            if ($duration <= 0.0) {
                $words = max(1, str_word_count(strip_tags($text)));
                $speed = (float) ($avatar?->voice_speed ?? 1.0) ?: 1.0;
                $duration = round($words / (2.6 * $speed), 1);
            }
            $duration = max(3.0, $duration);

            $actualProvider = $tts->lastProvider();

            $scene->update([
                // A run that got this far succeeded, so any warning from a PREVIOUS run is stale.
                // Clear it first and let warnIfDowngraded re-raise it below if it still applies —
                // otherwise a scene stays flagged forever after one bad generation, and the
                // warning stops meaning anything.
                'error_message' => null,
                'audio_path' => $path,
                'audio_alignment' => $narrationTiming->toArray(),
                'audio_script_hash' => sha1($script),
                // The language this audio is actually IN, so nothing downstream has to infer it.
                'audio_locale' => $locale,
                // ...and WHO actually read it, so a silent downgrade to a robot voice is a fact on
                // the row rather than something you have to hear to discover.
                'audio_provider' => $actualProvider,
                // The voice that SPOKE, from the service, not the one this job asked for.
                'audio_voice' => $tts->lastVoice() ?: ($voiceId !== '' ? $voiceId : null),
                'duration_seconds' => (int) ceil($duration),
            ]);

            $this->warnIfDowngraded($scene, $provider, $actualProvider, $voiceId, $narrationTiming);

            $this->maybeMarkReady($scene->fresh());
        } catch (Throwable $e) {
            $scene->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Say so — in the log and on the scene — when the audio did not come from the provider the
     * lesson asked for.
     *
     * The fallback chain is deliberate: half a lesson in the wrong voice still beats a lesson
     * that won't generate. What is NOT acceptable is that the substitution left no trace, so a
     * lesson could be described as narrated by a cloned voice while every scene was machine-read.
     * A downgrade is recorded as a warning the teacher can see, not a silent success.
     */
    private function warnIfDowngraded(
        Scene $scene,
        string $requested,
        ?string $actual,
        string $voiceId,
        NarrationTiming $timing,
    ): void {
        // 'auto' asks for the top of the chain, which is ElevenLabs.
        $wanted = $requested === 'auto' ? 'elevenlabs' : $requested;

        if ($actual === null || $actual === $wanted) {
            // The requested provider delivered. Still worth noting when a narrator that should
            // return timings did not — that is a drifting-subtitle scene.
            if ($actual === 'elevenlabs' && $timing->isEmpty()) {
                Log::warning("[Narration] scene {$scene->id}: ElevenLabs audio arrived without timings; subtitles will be estimated.");
            }

            return;
        }

        Log::warning(
            "[Narration] scene {$scene->id} DOWNGRADED: asked for {$requested}"
            .($voiceId !== '' ? " (voice {$voiceId})" : '')
            ." but the audio came from {$actual}. The narrator's voice was not used."
        );

        $scene->forceFill([
            'error_message' => __('Narrated by :actual, not the :wanted voice this lesson uses. Check the narration settings, then regenerate the audio.', [
                'actual' => $actual,
                'wanted' => $wanted,
            ]),
        ])->save();
    }
}
