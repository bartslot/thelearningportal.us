<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TtsService
{
    private ?string $generatedAudioExtension = null;

    private function lessonDisk()
    {
        return Storage::disk('public');
    }

    public function prepareSpeechText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split('/\n+/', $text) ?: [];
        $speech = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($this->isHeadingLine($line)) {
                continue;
            }

            $line = preg_replace('/\*\*(.*?)\*\*/', '$1', $line) ?? $line;
            $line = preg_replace('/\*(.*?)\*/', '$1', $line) ?? $line;
            $line = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $line) ?? $line;
            $line = preg_replace('/^[\-*•]\s+/', '', $line) ?? $line;
            $line = preg_replace('/^\d+[.)]\s+/', '', $line) ?? $line;
            $line = preg_replace('/\s+/', ' ', $line) ?? $line;

            if ($line !== '') {
                $speech[] = $line;
            }
        }

        return trim(implode("\n\n", $speech));
    }

    /**
     * Returns the file extension ('mp3' or 'm4a') produced by the last generateAudioRaw() call.
     */
    public function lastExtension(): string
    {
        return $this->generatedAudioExtension ?? 'mp3';
    }

    public function generateAudioRaw(string $text, string $voiceId, float $speed = 1.0, string $provider = 'auto', ?array &$timingData = null): ?string
    {
        $this->generatedAudioExtension = 'mp3'; // default; overridden by tryMacosTts
        $text = $this->prepareSpeechText($text);
        $timingData = null;

        if ($provider === 'elevenlabs') {
            return $this->tryElevenLabs($text, $voiceId, $timingData)
                ?? $this->tryPocketTts($text, $voiceId)
                ?? $this->tryEdgeTts($text, $voiceId, $speed, true, $timingData)
                ?? $this->tryMacosTts($text);
        }

        if ($provider === 'pocket_tts') {
            return $this->tryPocketTts($text, $voiceId)
                ?? $this->tryEdgeTts($text, $voiceId, $speed, true, $timingData)
                ?? $this->tryMacosTts($text);
        }

        if ($provider === 'edge_tts') {
            return $this->tryEdgeTts($text, $voiceId, $speed, true, $timingData)
                ?? $this->tryPocketTts($text, $voiceId)
                ?? $this->tryMacosTts($text);
        }

        // 'auto' or unknown: ElevenLabs → PocketTTS → edge-tts → macOS
        return $this->tryElevenLabs($text, $voiceId, $timingData)
            ?? $this->tryPocketTts($text, $voiceId)
            ?? $this->tryEdgeTts($text, $voiceId, $speed, true, $timingData)
            ?? $this->tryMacosTts($text);
    }

    /**
     * Convert text to speech and save the audio file.
     * Returns the storage path or null on failure.
     */
    public function generateAudio(string $text, string $lessonId, string $voice = 'alloy', float $speed = 1.0): ?string
    {
        $text = $this->prepareSpeechText($text);
        $this->generatedAudioExtension = 'mp3';
        $timingData = null;

        // If the avatar explicitly chose edge_tts, try it first
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

        return $result['audio'];
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
    private function tryEdgeTts(string $text, string $voice, float $speed = 1.0, bool $collectWordTimings = false, ?array &$timingData = null): ?string
    {
        // edge-tts requires Python and the edge-tts package
        $python = $this->pythonBinary();
        $timingData = null;

        // Check if edge_tts module is available
        $checkCmd = escapeshellcmd($python) . ' -c "import edge_tts" 2>/dev/null';
        exec($checkCmd, $out, $rc);
        if ($rc !== 0) {
            Log::info('TtsService: edge-tts not installed, skipping. Run: pip install edge-tts');
            return null;
        }

        try {
            $tempDir = sys_get_temp_dir() . '/tlp-tts';
            if (! is_dir($tempDir)) {
                @mkdir($tempDir, 0777, true);
            }

            $outFile = $tempDir . '/' . uniqid('edge-', true) . '.mp3';
            $errFile = $tempDir . '/' . uniqid('edge-err-', true) . '.log';
            $scriptFile = $tempDir . '/' . uniqid('edge-script-', true) . '.py';
            $timingsFile = $tempDir . '/' . uniqid('edge-timings-', true) . '.json';

            // Convert speed multiplier → percentage string: 0.92 → "-8%", 1.1 → "+10%"
            $ratePercent = (int) round(($speed - 1.0) * 100);
            $rateStr     = ($ratePercent >= 0 ? "+{$ratePercent}%" : "{$ratePercent}%");

            $pyScript = <<<PY
import asyncio
import edge_tts
import json

TEXT = {$this->pythonStringLiteral($text)}
VOICE = {$this->pythonStringLiteral($voice)}
RATE = {$this->pythonStringLiteral($rateStr)}
OUT_FILE = {$this->pythonStringLiteral($outFile)}
TIMINGS_FILE = {$this->pythonStringLiteral($timingsFile)}
COLLECT_TIMINGS = {$this->pythonStringLiteral($collectWordTimings ? '1' : '0')}

# edge-tts v7 only emits 'audio' and 'SentenceBoundary'.
# WordBoundary and VisemeEvent were dropped in v7.
# Visemes require either ElevenLabs /with-timestamps or Azure Speech SDK.

async def main():
    communicate = edge_tts.Communicate(TEXT, VOICE, rate=RATE)
    with open(OUT_FILE, "wb") as audio:
        async for chunk in communicate.stream():
            if chunk.get("type") == "audio":
                audio.write(chunk.get("data", b""))

asyncio.run(main())
PY;

            file_put_contents($scriptFile, $pyScript);

            $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($scriptFile) . ' 2>' . escapeshellarg($errFile);
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

            if ($collectWordTimings && file_exists($timingsFile)) {
                $decoded = json_decode((string) file_get_contents($timingsFile), true);
                if (is_array($decoded)) {
                    $timingData = array_values(array_filter(array_map(function ($item) {
                        if (! is_array($item)) {
                            return null;
                        }
                        $text = trim((string) ($item['text'] ?? ''));
                        $start = (float) ($item['start'] ?? 0);
                        $end = (float) ($item['end'] ?? $start);
                        if ($text === '') {
                            return null;
                        }
                        return [
                            'text' => $text,
                            'start' => max(0, $start),
                            'end' => max($start, $end),
                        ];
                    }, $decoded)));
                }
                @unlink($timingsFile);
            }

            $content = file_get_contents($outFile);
            @unlink($outFile);

            $this->generatedAudioExtension = 'mp3';

            Log::info("TtsService: edge-tts succeeded with voice {$voice}");
            return is_string($content) && strlen($content) > 100 ? $content : null;

        } catch (\Throwable $e) {
            Log::error('TtsService::tryEdgeTts failed: ' . $e->getMessage());
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
            $tempDir = sys_get_temp_dir() . '/thelearningportal-tts';

            if (! is_dir($tempDir)) {
                @mkdir($tempDir, 0777, true);
            }

            $basePath = tempnam($tempDir, 'tts-');
            if ($basePath === false) {
                return null;
            }

            $m4aPath = $basePath . '.m4a';
            @unlink($basePath);

            $command = '/usr/bin/say -o ' . escapeshellarg($m4aPath) . ' ' . escapeshellarg($text);
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
                $mp3Path = $basePath . '.mp3';
                $convert = 'ffmpeg -y -i ' . escapeshellarg($m4aPath) . ' -codec:a libmp3lame -q:a 4 '
                    . escapeshellarg($mp3Path) . ' >/dev/null 2>&1';
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

            return is_string($audioContent) && $audioContent !== '' ? $audioContent : null;
        } catch (\Throwable $e) {
            Log::error('TtsService::tryMacosTts failed: ' . $e->getMessage());
        }

        return null;
    }

    private function tryOpenAiTts(string $text, string $voice): ?string
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) return null;

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => config('services.openai.tts_model', 'tts-1'),
                    'input' => $text,
                    'voice' => $voice,
                ]);

            if ($response->successful()) {
                return $response->body();
            }

            Log::error('TtsService OpenAI error', ['status' => $response->status()]);
        } catch (\Exception $e) {
            Log::error('TtsService::tryOpenAiTts failed: ' . $e->getMessage());
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
            . ' -c "import importlib.util,sys; sys.exit(0 if importlib.util.find_spec('
            . var_export($module, true)
            . ') else 1)" 2>/dev/null';

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
        return "'" . str_replace(
            ["\\", "'"],
            ["\\\\", "\\'"],
            $value
        ) . "'";
    }
}
