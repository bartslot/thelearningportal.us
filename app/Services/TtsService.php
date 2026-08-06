<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TtsService
{
    private ?string $generatedAudioExtension = null;

    /** Which rung of the fallback chain actually produced the last audio. */
    private ?string $generatedProvider = null;

    /** The voice that rung actually spoke with — not the one the caller asked for. */
    private ?string $generatedVoice = null;

    private function lessonDisk()
    {
        return Storage::disk('public');
    }

    /**
     * Normalise a narration script for TTS while preserving the teacher's PARAGRAPH structure.
     *
     * A blank line (\n\n) is a paragraph boundary — kept as \n\n so the engine treats each
     * paragraph as a distinct topic (its own intonation contour). A single newline (\n) is a
     * SOFT break within a paragraph — collapsed to a space so the engine reads it as one
     * continuous thought, NOT a new topic. (Previously every newline was flattened to \n\n, so
     * a soft line break wrongly reset the intonation mid-paragraph.)
     */
    public function prepareSpeechText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [];
        $out = [];

        foreach ($paragraphs as $para) {
            $clean = [];

            foreach (preg_split('/\n+/', $para) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || $this->isHeadingLine($line)) {
                    continue;
                }

                $line = preg_replace('/\*\*(.*?)\*\*/', '$1', $line) ?? $line;
                $line = preg_replace('/\*(.*?)\*/', '$1', $line) ?? $line;
                $line = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $line) ?? $line;
                $line = preg_replace('/^[\-*•]\s+/', '', $line) ?? $line;
                $line = preg_replace('/^\d+[.)]\s+/', '', $line) ?? $line;
                $line = preg_replace('/\s+/', ' ', $line) ?? $line;

                if ($line !== '') {
                    $clean[] = $line;
                }
            }

            if ($clean !== []) {
                $out[] = implode(' ', $clean);   // soft newlines → spaces (one topic per paragraph)
            }
        }

        return trim(implode("\n\n", $out));
    }

    /**
     * Returns the file extension ('mp3' or 'm4a') produced by the last generateAudioRaw() call.
     */
    public function lastExtension(): string
    {
        return $this->generatedAudioExtension ?? 'mp3';
    }

    /**
     * Which provider actually produced the last audio — 'elevenlabs', 'azure', 'piper',
     * 'pocket_tts', 'edge_tts', 'macos_say', 'openai' — or null if nothing did.
     *
     * The chain falls through silently by design, so the requested provider says nothing about
     * what the listener will hear. Callers that care (narration attributed to a named voice)
     * must compare this against what they asked for. See GenerateSceneAudio.
     */
    public function lastProvider(): ?string
    {
        return $this->generatedProvider;
    }

    /**
     * The voice that actually spoke the last audio.
     *
     * Deliberately the voice USED, not the voice requested: a caller that asked with an empty
     * voice id used to get Azure's American default, and recording the request would have stored
     * an empty string — which is exactly how 14 scenes of a French lesson sat in the database
     * looking unremarkable while being read in US English.
     */
    public function lastVoice(): ?string
    {
        return $this->generatedVoice;
    }

    public function generateAudioRaw(string $text, string $voiceId, float $speed = 1.0, string $provider = 'auto', ?array &$timingData = null): ?string
    {
        $this->generatedAudioExtension = 'mp3'; // default; overridden by tryMacosTts
        $this->generatedProvider = null;
        $this->generatedVoice = null;
        $text = $this->prepareSpeechText($text);
        $timingData = null;

        if ($provider === 'azure') {
            return $this->tryAzure($text, $voiceId, $speed)
                ?? $this->tryEdgeTts($text, $voiceId, $speed)
                ?? $this->tryMacosTts($text);
        }

        if ($provider === 'elevenlabs') {
            return $this->tryElevenLabs($text, $voiceId, $timingData)
                ?? $this->tryAzure($text, $voiceId, $speed)
                ?? $this->tryPocketTts($text, $voiceId)
                ?? $this->tryEdgeTts($text, $voiceId, $speed)
                ?? $this->tryMacosTts($text);
        }

        if ($provider === 'pocket_tts') {
            return $this->tryPocketTts($text, $voiceId)
                ?? $this->tryEdgeTts($text, $voiceId, $speed)
                ?? $this->tryMacosTts($text);
        }

        // Self-hosted Piper (Oracle box) — cheap Dutch narration for drafts/previews. Falls back
        // to Azure/edge if the box is down so lesson generation never hard-fails.
        if ($provider === 'piper') {
            return $this->tryPiper($text, $voiceId)
                ?? $this->tryAzure($text, $voiceId, $speed)
                ?? $this->tryEdgeTts($text, $voiceId, $speed)
                ?? $this->tryMacosTts($text);
        }

        if ($provider === 'edge_tts') {
            return $this->tryEdgeTts($text, $voiceId, $speed)
                ?? $this->tryPocketTts($text, $voiceId)
                ?? $this->tryMacosTts($text);
        }

        // 'auto' or unknown: ElevenLabs → Azure → PocketTTS → edge-tts → macOS
        return $this->tryElevenLabs($text, $voiceId, $timingData)
            ?? $this->tryAzure($text, $voiceId, $speed)
            ?? $this->tryPocketTts($text, $voiceId)
            ?? $this->tryEdgeTts($text, $voiceId, $speed)
            ?? $this->tryMacosTts($text);
    }

    private function tryAzure(string $text, string $voiceId, float $speed = 1.0): ?string
    {
        $key = (string) config('services.azure_speech.key', '');
        $region = (string) config('services.azure_speech.region', 'eastus');
        if ($key === '' || $text === '') {
            return null;
        }

        // No voice, no narration. This used to default to 'en-US-GuyNeural', which meant any
        // caller that reached here without a voice got an AMERICAN one — and 14 scenes of a
        // French lesson were read in US English before anybody noticed. Narration is never
        // US-accented (see NarrationVoice), so refuse rather than guess: the caller is expected
        // to resolve a voice for the content language first.
        if ($voiceId === '') {
            Log::warning('[Azure TTS] refused: no voice supplied. The caller must pick one for the content language.');

            return null;
        }

        // Reject non-Azure voice IDs (Azure voices match xx-XX-NameNeural, optionally :DragonHD…)
        $candidateVoice = $voiceId;
        if (! preg_match('/^[a-z]{2}-[A-Z]{2}-.+Neural$/', $candidateVoice)) {
            Log::warning('[Azure TTS] skipped — voice ID does not look like an Azure Neural voice: '.$candidateVoice);

            return null;
        }
        $voice = $candidateVoice;
        // Document language follows the voice's own locale (nl-NL-FennaNeural → nl-NL), so
        // locale-sensitive reading (years like "1566", dates) matches the narration language.
        $lang = implode('-', array_slice(explode('-', $voice), 0, 2));
        $ratePct = (int) round(($speed - 1.0) * 100);   // 1.0 → +0%, 1.1 → +10%, etc.
        $rateAttr = ($ratePct >= 0 ? '+' : '').$ratePct.'%';
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $ssml = <<<SSML
<speak version="1.0" xml:lang="{$lang}">
  <voice name="{$voice}">
    <prosody rate="{$rateAttr}">{$escaped}</prosody>
  </voice>
</speak>
SSML;

        try {
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $key,
                'Content-Type' => 'application/ssml+xml',
                'X-Microsoft-OutputFormat' => 'audio-24khz-48kbitrate-mono-mp3',
                'User-Agent' => 'TheLearningPortal',
            ])
            // A full scene of narration is a minute or more of speech, and Azure streams it back as
            // it synthesises. From SiteGround that regularly ran past 25s with ~280 KB already
            // received, so every long scene failed with cURL 28 and silently lost its audio. The
            // connect timeout stays short — an unreachable endpoint should still fail fast.
                ->timeout(180)
                ->connectTimeout(5)
                ->withBody($ssml, 'application/ssml+xml')
                ->post("https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1");

            if (! $response->successful()) {
                Log::error('[Azure TTS] HTTP '.$response->status().' (voice='.$voice.'): '.substr($response->body(), 0, 400));

                return null;
            }

            $this->generatedAudioExtension = 'mp3';
            $this->generatedProvider = 'azure';
            $this->generatedVoice = $voice;

            return $response->body();
        } catch (\Throwable $e) {
            Log::error('[Azure TTS] exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Convert text to speech and save the audio file.
     * Returns the storage path or null on failure.
     */
    public function generateAudio(string $text, string $lessonId, string $voice = 'alloy', float $speed = 1.0): ?string
    {
        $text = $this->prepareSpeechText($text);
        $this->generatedAudioExtension = 'mp3';
        $this->generatedProvider = null;
        $this->generatedVoice = null;
        $timingData = null;

        // If the narrator explicitly chose edge_tts, try it first
        if (str_starts_with($voice, 'es-') || str_starts_with($voice, 'en-GB-') || str_starts_with($voice, 'en-US-')) {
            $audioContent = $this->tryEdgeTts($text, $voice, $speed)
                ?? $this->tryPocketTts($text)
                ?? $this->tryMacosTts($text);
        } else {
            $audioContent = $this->tryElevenLabs($text, $voice, $timingData)
                ?? $this->tryPocketTts($text, $voice)
                ?? $this->tryEdgeTts($text, 'es-ES-AlvaroNeural', $speed)
                ?? $this->tryMacosTts($text);
        }

        if ($audioContent === null) {
            Log::error("TtsService: all providers failed for lesson {$lessonId}");

            return null;
        }

        $audioPath = "lessons/{$lessonId}/audio.{$this->generatedAudioExtension}";

        $this->lessonDisk()->put($audioPath, $audioContent);

        return $audioPath;
    }

    private function tryElevenLabs(string $text, string $voiceId, ?array &$timingData = null): ?string
    {
        /** @var \App\Services\ElevenLabsService $service */
        $service = app(\App\Services\ElevenLabsService::class);

        $result = $service->generateWithTimestamps($text, $voiceId);

        if ($result === null) {
            return null;
        }

        $timingData = ['character_timings' => $result['alignment']];
        $this->generatedProvider = 'elevenlabs';
        $this->generatedVoice = $voiceId;

        return $result['audio'];
    }

    /**
     * Self-hosted Piper TTS (Oracle Always-Free box). JSON API: POST /tts {text, voice} -> WAV.
     * The voice is server-side config (nl_NL-pim-medium) — the app's narrator voiceId is ElevenLabs-
     * shaped and irrelevant here, so we don't pass it. Returns null on any failure to fall through.
     */
    private function tryPiper(string $text, string $voiceId = ''): ?string
    {
        $url = config('services.piper.url');
        if (! $url) {
            return null;
        }

        // Use the caller's Piper voice (already language-mapped) when it looks like a Piper voice
        // (e.g. "nl_NL-pim-medium"); otherwise the configured default.
        $voice = preg_match('/^[a-z]{2}_[A-Z]{2}-/', $voiceId)
            ? $voiceId
            : (string) config('services.piper.voice', 'nl_NL-pim-medium');

        try {
            $request = Http::timeout(90)->accept('audio/mpeg, */*');
            $token = config('services.piper.token');
            if ($token) {
                $request = $request->withToken($token);
            }

            $response = $request->post(rtrim((string) $url, '/').'/tts', [
                'text' => $text,
                'voice' => $voice,
            ]);

            if (! $response->successful() || $response->body() === '') {
                return null;
            }

            $this->generatedAudioExtension = 'mp3';   // server transcodes Piper WAV -> mp3
            $this->generatedProvider = 'piper';
            $this->generatedVoice = $voice;

            return $response->body();
        } catch (\Throwable $e) {
            Log::warning('[Piper TTS] failed: '.$e->getMessage());

            return null;
        }
    }

    private function tryPocketTts(string $text, string $voiceId = ''): ?string
    {
        $url = config('services.pocket_tts.url');

        if (! $url) {
            return null;
        }

        try {
            $request = Http::timeout(60)->accept('audio/wav, audio/mpeg, */*');

            $hfToken = config('services.pocket_tts.hf_token');
            if ($hfToken) {
                $request = $request->withToken($hfToken);
            }

            $multipart = [['name' => 'text', 'contents' => $text]];
            if ($voiceId !== '') {
                $multipart[] = ['name' => 'voice_id', 'contents' => $voiceId];
            }

            $response = $request->asMultipart()->post("{$url}/tts", $multipart);

            if (! $response->successful()) {
                return null;
            }

            $this->generatedProvider = 'pocket_tts';
            $this->generatedVoice = $voiceId !== '' ? $voiceId : null;

            return $response->body();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isHeadingLine(string $line): bool
    {
        return (bool) preg_match(
            '/^(?:#{1,6}\s+.+|\*\*\[(?:.+)\]\*\*|\[(?:.+)\])$/',
            $line
        );
    }

    private function isRunning(string $baseUrl): bool
    {
        try {
            Http::timeout(2)->get($baseUrl);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * edge-tts: Microsoft Edge browser neural TTS via Python CLI.
     * Completely FREE — no API key, no account.
     * Install: pip install edge-tts
     *
     * Key voice for The Professor: es-ES-AlvaroNeural
     * (Spanish male that speaks English with a Spanish accent)
     *
     * Speed is converted from a multiplier (0.92) to a rate string (e.g. "-8%").
     */
    private function tryEdgeTts(string $text, string $voice, float $speed = 1.0): ?string
    {
        // edge-tts requires Python and the edge-tts package
        $python = $this->pythonBinary();

        // Check if edge_tts module is available
        $checkCmd = escapeshellcmd($python).' -c "import edge_tts" 2>/dev/null';
        exec($checkCmd, $out, $rc);
        if ($rc !== 0) {
            Log::info('TtsService: edge-tts not installed, skipping. Run: pip install edge-tts');

            return null;
        }

        try {
            $tempDir = sys_get_temp_dir().'/tlp-tts';
            if (! is_dir($tempDir)) {
                @mkdir($tempDir, 0777, true);
            }

            $outFile = $tempDir.'/'.uniqid('edge-', true).'.mp3';
            $errFile = $tempDir.'/'.uniqid('edge-err-', true).'.log';
            $scriptFile = $tempDir.'/'.uniqid('edge-script-', true).'.py';

            // Convert speed multiplier → percentage string: 0.92 → "-8%", 1.1 → "+10%"
            $ratePercent = (int) round(($speed - 1.0) * 100);
            $rateStr = ($ratePercent >= 0 ? "+{$ratePercent}%" : "{$ratePercent}%");

            $pyScript = <<<PY
import asyncio
import edge_tts

TEXT = {$this->pythonStringLiteral($text)}
VOICE = {$this->pythonStringLiteral($voice)}
RATE = {$this->pythonStringLiteral($rateStr)}
OUT_FILE = {$this->pythonStringLiteral($outFile)}

# edge-tts v7 only emits 'audio' and 'SentenceBoundary'.
# WordBoundary and VisemeEvent were dropped in v7, so this rung returns audio and no
# timings at all — a scene narrated here gets even-split subtitles. Character timings
# need ElevenLabs /with-timestamps or the Azure Speech SDK.

async def main():
    communicate = edge_tts.Communicate(TEXT, VOICE, rate=RATE)
    with open(OUT_FILE, "wb") as audio:
        async for chunk in communicate.stream():
            if chunk.get("type") == "audio":
                audio.write(chunk.get("data", b""))

asyncio.run(main())
PY;

            file_put_contents($scriptFile, $pyScript);

            // `timeout` is GNU coreutils and ships on Linux but not on macOS, where it is
            // `gtimeout` and only if coreutils is installed — which it usually is not. Hard-coding
            // it made this whole rung dead on every Mac: the shell answered "gtimeout: command not
            // found", `|| true` swallowed that, and the chain fell through to macOS `say`. Run
            // unwrapped when there is no timeout binary; the queue job's own timeout is the
            // backstop for a hung synthesis.
            $wrappedPython = escapeshellcmd($python);
            $timeoutBin = collect(['timeout', 'gtimeout'])->first(fn (string $bin) => $this->commandExists($bin));
            if ($timeoutBin === null) {
                Log::info('TtsService: no timeout binary (install coreutils for gtimeout); running edge-tts unwrapped.');
            }
            $runner = $timeoutBin !== null ? "{$timeoutBin} 55 {$wrappedPython}" : $wrappedPython;
            $cmd = "({$runner} ".escapeshellarg($scriptFile).' 2>'.escapeshellarg($errFile).') || true';
            exec($cmd, $output, $exitCode);
            $stderr = file_exists($errFile) ? trim((string) file_get_contents($errFile)) : '';
            @unlink($scriptFile);
            @unlink($errFile);

            if ($exitCode !== 0 || ! file_exists($outFile) || filesize($outFile) < 100) {
                Log::warning('TtsService: edge-tts generation failed', [
                    'voice' => $voice,
                    'exit' => $exitCode,
                    'stderr_tail' => $stderr !== '' ? substr($stderr, -300) : null,
                ]);
                @unlink($outFile);

                return null;
            }

            $content = file_get_contents($outFile);
            @unlink($outFile);

            $this->generatedAudioExtension = 'mp3';
            $this->generatedProvider = 'edge_tts';
            $this->generatedVoice = $voice;

            Log::info("TtsService: edge-tts succeeded with voice {$voice}");

            return is_string($content) && strlen($content) > 100 ? $content : null;

        } catch (\Throwable $e) {
            Log::error('TtsService::tryEdgeTts failed: '.$e->getMessage());

            return null;
        }
    }

    private function tryMacosTts(string $text): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return null;
        }

        if (! $this->commandExists('say')) {
            return null;
        }

        try {
            $tempDir = sys_get_temp_dir().'/thelearningportal-tts';

            if (! is_dir($tempDir)) {
                @mkdir($tempDir, 0777, true);
            }

            $basePath = tempnam($tempDir, 'tts-');
            if ($basePath === false) {
                return null;
            }

            $m4aPath = $basePath.'.m4a';
            @unlink($basePath);

            $command = '/usr/bin/say -o '.escapeshellarg($m4aPath).' '.escapeshellarg($text);
            exec($command, $output, $exitCode);

            if ($exitCode !== 0 || ! file_exists($m4aPath)) {
                Log::warning('TtsService: macOS say fallback failed');
                @unlink($m4aPath);

                return null;
            }

            $this->generatedAudioExtension = 'm4a';
            $audioContent = file_get_contents($m4aPath);

            // Prefer mp3 output for browser compatibility and waveform decoding when possible.
            if ($this->commandExists('ffmpeg')) {
                $mp3Path = $basePath.'.mp3';
                $convert = 'ffmpeg -y -i '.escapeshellarg($m4aPath).' -codec:a libmp3lame -q:a 4 '
                    .escapeshellarg($mp3Path).' >/dev/null 2>&1';
                exec($convert, $ffOut, $ffCode);

                if ($ffCode === 0 && file_exists($mp3Path) && filesize($mp3Path) > 100) {
                    $mp3Content = file_get_contents($mp3Path);
                    @unlink($mp3Path);
                    if (is_string($mp3Content) && $mp3Content !== '') {
                        $this->generatedAudioExtension = 'mp3';
                        $audioContent = $mp3Content;
                    }
                } else {
                    @unlink($mp3Path);
                }
            }

            @unlink($m4aPath);

            if (! is_string($audioContent) || $audioContent === '') {
                return null;
            }

            // Last rung. Everything above it failed, and the listener is about to hear the
            // machine voice instead of whichever narrator the lesson names — loudly, because
            // this is exactly the downgrade that went unnoticed for months.
            Log::warning('[TTS] FELL BACK TO macOS `say` — the lesson narrator\'s voice was NOT used.');
            $this->generatedProvider = 'macos_say';
            $this->generatedVoice = 'say';

            return $audioContent;
        } catch (\Throwable $e) {
            Log::error('TtsService::tryMacosTts failed: '.$e->getMessage());
        }

        return null;
    }

    private function tryOpenAiTts(string $text, string $voice): ?string
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => config('services.openai.tts_model', 'tts-1'),
                    'input' => $text,
                    'voice' => $voice,
                ]);

            if ($response->successful()) {
                $this->generatedProvider = 'openai';
                $this->generatedVoice = $voice;

                return $response->body();
            }

            Log::error('TtsService OpenAI error', ['status' => $response->status()]);
        } catch (\Exception $e) {
            Log::error('TtsService::tryOpenAiTts failed: '.$e->getMessage());
        }

        return null;
    }

    private function commandExists(string $command): bool
    {
        exec("command -v {$command} 2>/dev/null", $out, $rc);

        return $rc === 0;
    }

    private function pythonModuleAvailable(string $python, string $module): bool
    {
        $command = escapeshellcmd($python)
            .' -c "import importlib.util,sys; sys.exit(0 if importlib.util.find_spec('
            .var_export($module, true)
            .') else 1)" 2>/dev/null';

        exec($command, $out, $rc);

        return $rc === 0;
    }

    private function pythonBinary(): string
    {
        $venvPython = base_path('.venv/bin/python3');

        return file_exists($venvPython) ? $venvPython : 'python3';
    }

    private function pythonStringLiteral(string $value): string
    {
        return "'".str_replace(
            ['\\', "'"],
            ['\\\\', "\\'"],
            $value
        )."'";
    }
}
