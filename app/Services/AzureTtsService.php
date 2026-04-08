<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AzureTtsException;
use Illuminate\Support\Facades\Log;

class AzureTtsService
{
    public function synthesize(string $ssml, string $storagePrefix): array
    {
        // Log character usage for free-tier monitoring (Azure F0: 500K chars/month)
        Log::info('AzureTts synthesis', [
            'chars'  => strlen($ssml),
            'prefix' => $storagePrefix,
        ]);

        // Write SSML to temp file
        $ssmlFile  = sys_get_temp_dir() . '/azure_ssml_' . uniqid() . '.xml';
        file_put_contents($ssmlFile, $ssml);

        $audioOut  = storage_path("app/{$storagePrefix}/audio_3d.mp3");
        $shapesOut = storage_path("app/{$storagePrefix}/blendshapes.json");

        // Ensure directories exist
        @mkdir(dirname($audioOut),  0755, true);
        @mkdir(dirname($shapesOut), 0755, true);

        $python = base_path('scripts/azure_tts.py');
        $key    = (string) config('services.azure_speech.key', '');
        $region = (string) config('services.azure_speech.region', '');

        $cmd = sprintf(
            'python3 %s --ssml %s --key %s --region %s --audio-out %s --shapes-out %s 2>&1',
            escapeshellarg($python),
            escapeshellarg($ssmlFile),
            escapeshellarg($key),
            escapeshellarg($region),
            escapeshellarg($audioOut),
            escapeshellarg($shapesOut),
        );

        [$output, $exitCode] = $this->runPython($cmd);

        @unlink($ssmlFile);

        if ($exitCode !== 0) {
            throw new AzureTtsException(implode("\n", $output));
        }

        return [
            'audio_3d_path'    => "{$storagePrefix}/audio_3d.mp3",
            'blendshapes_path' => "{$storagePrefix}/blendshapes.json",
        ];
    }

    /**
     * Isolated for testing — override in anonymous subclass to avoid real Python calls.
     *
     * @return array{0: string[], 1: int}
     */
    protected function runPython(string $cmd): array
    {
        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [$output, $exitCode];
    }
}
