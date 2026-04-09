<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AnimationClip;
use Illuminate\Support\Facades\Log;

class FbxConversionService
{
    /** Convert one FBX clip to GLB and strip root motion. Updates clip status. */
    public function convert(AnimationClip $clip): void
    {
        $binary = config('services.fbx2gltf.binary', 'fbx2gltf');

        if (! $this->isInstalled()) {
            $clip->update(['status' => 'failed', 'conversion_error' => 'fbx2gltf not found. Install from https://github.com/facebookincubator/FBX2glTF']);
            throw new \RuntimeException('fbx2gltf not found — see SETUP.md');
        }

        $fbxAbsolute = $clip->fbxAbsolutePath();
        $glbRelative = $this->expectedGlbPath($clip);
        $glbAbsolute = public_path($glbRelative);

        // Step 1: Run fbx2gltf
        $cmd = escapeshellcmd($binary)
            . ' --input '  . escapeshellarg($fbxAbsolute)
            . ' --output ' . escapeshellarg($glbAbsolute)
            . ' --no-reindex 2>&1';

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $error = implode("\n", $output);
            $clip->update(['status' => 'failed', 'conversion_error' => $error]);
            throw new \RuntimeException("fbx2gltf conversion failed: {$error}");
        }

        // Step 2: Strip root motion (non-fatal if script missing)
        $this->stripRootMotion($glbAbsolute);

        $clip->update([
            'status'           => 'ready',
            'glb_path'         => $glbRelative,
            'conversion_error' => null,
        ]);

        Log::info("FbxConversionService: converted {$clip->clip_id} → {$glbRelative}");
    }

    /** Compute the expected GLB path (relative to public/) for a given clip. */
    public function expectedGlbPath(AnimationClip $clip): string
    {
        return (string) preg_replace('/\.fbx$/i', '.glb', $clip->fbx_path);
    }

    /** True if the configured fbx2gltf binary can be found. */
    public function isInstalled(): bool
    {
        $binary = config('services.fbx2gltf.binary', 'fbx2gltf');

        exec('which ' . escapeshellarg($binary) . ' 2>/dev/null', $out, $code);

        if ($code === 0) {
            return true;
        }

        // Windows fallback
        exec('where ' . escapeshellarg($binary) . ' 2>NUL', $out2, $code2);

        return $code2 === 0;
    }

    /** Strip X/Z translation (root motion) from the GLB hip bone. Non-fatal. */
    private function stripRootMotion(string $glbAbsolutePath): void
    {
        $script = base_path('scripts/strip-root-motion.mjs');

        if (! file_exists($script)) {
            Log::warning('FbxConversionService: strip-root-motion.mjs not found — root motion not stripped', [
                'glb' => $glbAbsolutePath,
            ]);
            return;
        }

        $cmd = 'node ' . escapeshellarg($script) . ' ' . escapeshellarg($glbAbsolutePath) . ' 2>&1';
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::warning('FbxConversionService: root motion strip failed (avatar may drift)', [
                'error' => implode("\n", $output),
            ]);
        }
    }
}
