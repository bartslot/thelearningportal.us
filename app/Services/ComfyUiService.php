<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ComfyUiService
{
    private function baseUrl(): string
    {
        return rtrim((string) config('services.comfyui.url', 'http://localhost:8188'), '/');
    }

    public function txt2img(
        string $prompt,
        string $negativePrompt = '',
        int    $width          = 1024,
        int    $height         = 512,
        int    $steps          = 4,
        float  $cfg            = 1.0,
    ): string {
        $this->ensureRunning();

        $workflow   = $this->buildWorkflow($prompt, $negativePrompt, $width, $height, $steps, $cfg);
        $clientId   = uniqid('lp_', more_entropy: true);
        $promptId   = $this->queuePrompt($workflow, $clientId);
        $outputFile = $this->waitForOutput($promptId);

        $bytes = $this->downloadImage($outputFile['filename'], $outputFile['subfolder'], $outputFile['type']);
        $this->freeMemory();

        return $bytes;
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(3)->connectTimeout(2)->get($this->baseUrl() . '/system_stats')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensureRunning(): void
    {
        if ($this->isAvailable()) {
            return;
        }

        $dir = (string) config('services.comfyui.dir', $_SERVER['HOME'] . '/ComfyUI');
        $bin = (string) config('services.comfyui.python', 'python');
        $cmd = "cd " . escapeshellarg($dir) . " && nohup {$bin} main.py --force-fp16 > /tmp/comfyui.log 2>&1 &";

        Log::info('[ComfyUI] Starting process: ' . $cmd);
        exec($cmd);

        // Wait up to 30s for ComfyUI to become available
        $deadline = time() + 30;
        while (time() < $deadline) {
            sleep(2);
            if ($this->isAvailable()) {
                Log::info('[ComfyUI] Process ready.');
                return;
            }
        }

        throw new RuntimeException('ComfyUI failed to start within 30 seconds.');
    }

    // ── Workflow ──────────────────────────────────────────────────────────────

    private function buildWorkflow(
        string $prompt,
        string $negativePrompt,
        int    $width,
        int    $height,
        int    $steps,
        float  $cfg,
    ): array {
        $checkpoint = (string) config('services.comfyui.checkpoint', 'flux1-schnell-Q5_K_S.gguf');
        $seed       = random_int(0, 999_999_999);
        $isGguf     = str_ends_with(strtolower($checkpoint), '.gguf');

        return $isGguf
            ? $this->ggufWorkflow($checkpoint, $prompt, $negativePrompt, $width, $height, $steps, $cfg, $seed)
            : $this->checkpointWorkflow($checkpoint, $prompt, $negativePrompt, $width, $height, $steps, $cfg, $seed);
    }

    // ── Workflow builders ─────────────────────────────────────────────────────

    private function ggufWorkflow(
        string $checkpoint, string $prompt, string $negativePrompt,
        int $width, int $height, int $steps, float $cfg, int $seed,
    ): array {
        $clip1 = (string) config('services.comfyui.clip1', 'clip_l.safetensors');
        $clip2 = (string) config('services.comfyui.clip2', 't5xxl_fp8_e4m3fn.safetensors');
        $vae   = (string) config('services.comfyui.vae',   'ae.safetensors');

        return [
            // GGUF unet loader (ComfyUI-GGUF custom node)
            '1'  => ['class_type' => 'UnetLoaderGGUF',      'inputs' => ['unet_name' => $checkpoint]],
            // DualCLIPLoaderGGUF supports mixed safetensors + GGUF clip files
            '2'  => ['class_type' => 'DualCLIPLoaderGGUF',  'inputs' => ['clip_name1' => $clip1, 'clip_name2' => $clip2, 'type' => 'flux']],
            // VAE loader
            '3'  => ['class_type' => 'VAELoader',            'inputs' => ['vae_name' => $vae]],
            // Text encoders
            '6'  => ['class_type' => 'CLIPTextEncode',       'inputs' => ['text' => $prompt,         'clip' => ['2', 0]]],
            '7'  => ['class_type' => 'CLIPTextEncode',       'inputs' => ['text' => $negativePrompt, 'clip' => ['2', 0]]],
            // Latent image
            '5'  => ['class_type' => 'EmptyLatentImage',     'inputs' => ['width' => $width, 'height' => $height, 'batch_size' => 1]],
            // Sampler
            '10' => ['class_type' => 'KSampler', 'inputs' => [
                'seed'         => $seed,
                'steps'        => $steps,
                'cfg'          => $cfg,
                'sampler_name' => (string) config('services.comfyui.sampler', 'euler'),
                'scheduler'    => (string) config('services.comfyui.scheduler', 'simple'),
                'denoise'      => 1.0,
                'model'        => ['1', 0],
                'positive'     => ['6', 0],
                'negative'     => ['7', 0],
                'latent_image' => ['5', 0],
            ]],
            '8'  => ['class_type' => 'VAEDecode',   'inputs' => ['samples' => ['10', 0], 'vae' => ['3', 0]]],
            '9'  => ['class_type' => 'SaveImage',   'inputs' => ['filename_prefix' => 'lp_scene', 'images' => ['8', 0]]],
        ];
    }

    private function checkpointWorkflow(
        string $checkpoint, string $prompt, string $negativePrompt,
        int $width, int $height, int $steps, float $cfg, int $seed,
    ): array {
        return [
            '4' => ['class_type' => 'CheckpointLoaderSimple', 'inputs' => ['ckpt_name' => $checkpoint]],
            '5' => ['class_type' => 'EmptyLatentImage',        'inputs' => ['width' => $width, 'height' => $height, 'batch_size' => 1]],
            '6' => ['class_type' => 'CLIPTextEncode',          'inputs' => ['text' => $prompt,         'clip' => ['4', 1]]],
            '7' => ['class_type' => 'CLIPTextEncode',          'inputs' => ['text' => $negativePrompt, 'clip' => ['4', 1]]],
            '3' => ['class_type' => 'KSampler', 'inputs' => [
                'seed'         => $seed,
                'steps'        => $steps,
                'cfg'          => $cfg,
                'sampler_name' => (string) config('services.comfyui.sampler', 'euler'),
                'scheduler'    => (string) config('services.comfyui.scheduler', 'simple'),
                'denoise'      => 1.0,
                'model'        => ['4', 0],
                'positive'     => ['6', 0],
                'negative'     => ['7', 0],
                'latent_image' => ['5', 0],
            ]],
            '8' => ['class_type' => 'VAEDecode', 'inputs' => ['samples' => ['3', 0], 'vae' => ['4', 2]]],
            '9' => ['class_type' => 'SaveImage', 'inputs' => ['filename_prefix' => 'lp_scene', 'images' => ['8', 0]]],
        ];
    }

    // ── API calls ─────────────────────────────────────────────────────────────

    private function queuePrompt(array $workflow, string $clientId): string
    {
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post($this->baseUrl() . '/prompt', [
                'prompt'    => $workflow,
                'client_id' => $clientId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('ComfyUI queue failed: ' . $response->status() . ' ' . substr($response->body(), 0, 200));
        }

        $promptId = $response->json('prompt_id');
        if (! is_string($promptId) || $promptId === '') {
            throw new RuntimeException('ComfyUI returned no prompt_id');
        }

        return $promptId;
    }

    private function waitForOutput(string $promptId): array
    {
        $timeout  = (int) config('services.comfyui.timeout', 180);
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            sleep(2);

            $history = Http::timeout(10)
                ->get($this->baseUrl() . '/history/' . $promptId);

            if (! $history->successful()) {
                continue;
            }

            $data = $history->json($promptId);
            if (! is_array($data)) {
                continue;
            }

            // Check for error
            if (isset($data['status']['status_str']) && $data['status']['status_str'] === 'error') {
                $msg = $data['status']['messages'][0][1] ?? 'unknown error';
                throw new RuntimeException('ComfyUI generation error: ' . $msg);
            }

            // Node 9 = SaveImage output
            $outputs = $data['outputs']['9']['images'] ?? null;
            if (is_array($outputs) && isset($outputs[0]['filename'])) {
                return $outputs[0];
            }
        }

        throw new RuntimeException("ComfyUI timed out after {$timeout}s for prompt {$promptId}");
    }

    private function freeMemory(): void
    {
        try {
            Http::timeout(10)->post($this->baseUrl() . '/free', [
                'unload_models' => true,
                'free_memory'   => true,
            ]);
            Log::info('[ComfyUI] Models unloaded, memory freed.');
        } catch (\Throwable $e) {
            Log::warning('[ComfyUI] Failed to free memory: ' . $e->getMessage());
        }
    }

    private function downloadImage(string $filename, string $subfolder, string $type): string
    {
        $url = $this->baseUrl() . '/view?' . http_build_query([
            'filename'  => $filename,
            'subfolder' => $subfolder,
            'type'      => $type,
        ]);

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('ComfyUI image download failed: ' . $response->status());
        }

        $bytes = $response->body();
        if ($bytes === '') {
            throw new RuntimeException('ComfyUI returned empty image');
        }

        return $bytes;
    }
}
