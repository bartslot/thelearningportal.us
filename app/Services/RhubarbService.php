<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RhubarbService
{
    private string $binary;

    public function __construct()
    {
        $this->binary = config('services.rhubarb.binary', '/opt/homebrew/bin/rhubarb');
    }

    /**
     * Run Rhubarb on an MP3 and return the storage path to the visemes JSON.
     * Returns null if Rhubarb is not installed or fails.
     */
    public function generateVisemes(string $audioStoragePath, string $lessonId): ?string
    {
        if (! is_file($this->binary) || ! is_executable($this->binary)) {
            Log::warning("RhubarbService: binary not found at {$this->binary}");
            return null;
        }

        $disk     = Storage::disk('public');
        $audioAbs = $disk->path($audioStoragePath);

        if (! $disk->exists($audioStoragePath)) {
            Log::warning("RhubarbService: audio file not found — {$audioStoragePath}");
            return null;
        }

        $outputPath    = "lessons/{$lessonId}/visemes.json";
        $outputAbs     = $disk->path($outputPath);

        // Rhubarb needs a WAV — convert MP3 if needed
        $inputAbs = $this->ensureWav($audioAbs, $lessonId);
        if (! $inputAbs) {
            return null;
        }

        $cmd = implode(' ', array_map('escapeshellarg', [
            $this->binary,
            '--recognizer', 'phonetic',
            '--exportFormat', 'json',
            '--output', $outputAbs,
            $inputAbs,
        ]));

        Log::info("RhubarbService: running {$cmd}");

        $output   = [];
        $exitCode = 0;
        exec("{$cmd} 2>&1", $output, $exitCode);

        // Clean up temp WAV if we created one
        if ($inputAbs !== $audioAbs) {
            @unlink($inputAbs);
        }

        if ($exitCode !== 0) {
            Log::warning('RhubarbService: failed', ['output' => implode("\n", $output)]);
            return null;
        }

        Log::info("RhubarbService: visemes saved at {$outputPath}");
        return $outputPath;
    }

    /**
     * Rhubarb requires WAV input. If the file is MP3, convert it via ffmpeg.
     * Returns the absolute path to a WAV file (may be a temp file).
     */
    private function ensureWav(string $audioAbs, string $lessonId): ?string
    {
        if (str_ends_with(strtolower($audioAbs), '.wav')) {
            return $audioAbs;
        }

        // Check ffmpeg is available
        exec('which ffmpeg 2>/dev/null', $out, $code);
        if ($code !== 0) {
            Log::warning('RhubarbService: ffmpeg not found — cannot convert MP3 to WAV');
            return null;
        }

        $wavPath = sys_get_temp_dir() . "/rhubarb_{$lessonId}.wav";
        $cmd     = implode(' ', array_map('escapeshellarg', [
            'ffmpeg', '-y', '-i', $audioAbs, '-ar', '16000', '-ac', '1', $wavPath,
        ]));

        exec("{$cmd} 2>&1", $output, $exitCode);

        if ($exitCode !== 0 || ! file_exists($wavPath)) {
            Log::warning('RhubarbService: ffmpeg conversion failed', ['output' => implode("\n", $output)]);
            return null;
        }

        return $wavPath;
    }
}
