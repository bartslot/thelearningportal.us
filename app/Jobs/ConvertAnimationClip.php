<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AnimationClip;
use App\Services\FbxConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ConvertAnimationClip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(public readonly int $clipId) {}

    public function handle(FbxConversionService $service): void
    {
        $clip = AnimationClip::findOrFail($this->clipId);
        $service->convert($clip);
    }

    public function failed(\Throwable $e): void
    {
        AnimationClip::where('id', $this->clipId)->update([
            'status'           => 'failed',
            'conversion_error' => $e->getMessage(),
        ]);
    }
}
