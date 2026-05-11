<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Avatar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TransferAvatarMorphs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(
        private readonly int $avatarId
    ) {}

    public function handle(): void
    {
        $avatar = Avatar::find($this->avatarId);
        if (! $avatar) {
            Log::error("TransferAvatarMorphs: avatar {$this->avatarId} not found");
            return;
        }

        $avatarDir = public_path("avatars/{$this->avatarId}");
        $script    = base_path('scripts/transfer_morphs_single.py');
        $blender   = '/Applications/Blender.app/Contents/MacOS/Blender';

        $cmd = escapeshellcmd($blender)
            . ' --background --python ' . escapeshellarg($script)
            . ' -- ' . escapeshellarg((string) $this->avatarId)
            . ' ' . escapeshellarg($avatarDir)
            . ' 2>&1';

        Log::info("TransferAvatarMorphs: starting Blender for avatar {$this->avatarId}");
        exec($cmd, $output, $exitCode);

        $logOutput = implode("\n", $output);
        Log::info("TransferAvatarMorphs: Blender exit={$exitCode}", ['output' => $logOutput]);

        if ($exitCode === 0) {
            $avatar->update(['morph_status' => 'ready']);
        } else {
            $avatar->update(['morph_status' => 'failed']);
            Log::error("TransferAvatarMorphs: Blender failed for avatar {$this->avatarId}", ['output' => $logOutput]);
        }
    }
}
