<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ElevenLabsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WarmElevenLabsJob implements ShouldQueue
{
    use Queueable;

    public function handle(ElevenLabsService $service): void
    {
        $service->getVoices();
    }
}
