<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ElevenLabsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WarmElevenLabsCache extends Command
{
    protected $signature = 'elevenlabs:warm';
    protected $description = 'Warm the ElevenLabs voice list cache';

    public function handle(ElevenLabsService $service): int
    {
        Cache::forget('elevenlabs_voices');

        $voices = $service->getVoices();

        if (empty($voices)) {
            $this->warn('ElevenLabs API returned no voices (check API key or connectivity).');
            return self::SUCCESS;
        }

        $this->info('ElevenLabs voice cache warmed: ' . count($voices) . ' voices.');
        return self::SUCCESS;
    }
}
