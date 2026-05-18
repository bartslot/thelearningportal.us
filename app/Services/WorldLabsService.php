<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * WorldLabs Marble 1.1 — generates a 3D Gaussian-splat world from an image.
 *
 * API ref: https://api.worldlabs.ai/marble/v1
 * Assets downloaded locally to storage/app/public/lessons/{id}/scenes/{id}/world/
 */
class WorldLabsService
{
    private const ENDPOINT   = 'https://api.worldlabs.ai/marble/v1';
    private const MODEL      = 'marble-1.1';
    private const POLL_DELAY = 15; // seconds between status checks

    public function __construct(
        private readonly string $apiKey,
        private readonly int    $maxPollSeconds = 600,
    ) {}

    /**
     * Submit a generation request and poll until done.
     * Returns an array with keys: pano_url, spz_urls, glb_url, operation_id.
     */
    public function generate(string $imageUrl, string $displayName, ?string $prompt = null): array
    {
        $request   = $this->buildRequest($imageUrl, $displayName, $prompt);
        $operation = $this->submit($request);
        $operationId = $this->operationId($operation);

        $completed = $this->poll($operationId);

        if (! empty($completed['error'])) {
            throw new RuntimeException('WorldLabs generation failed: ' . json_encode($completed['error']));
        }

        $response = $completed['response'] ?? $completed;
        $assets   = $response['assets'] ?? [];

        $semantics = $assets['splats']['semantics_metadata'] ?? [];

        return [
            'operation_id'     => $operationId,
            'pano_url'         => $assets['imagery']['pano_url'] ?? null,
            'spz_urls'         => $assets['splats']['spz_urls'] ?? [],
            'glb_url'          => $assets['mesh']['collider_mesh_url'] ?? null,
            'semantics'        => [
                'ground_plane_offset' => (float) ($semantics['ground_plane_offset'] ?? 0),
                'flip_y'              => (bool)  ($semantics['flip_y']              ?? true),
                'metric_scale_factor' => (float) ($semantics['metric_scale_factor'] ?? 1),
            ],
        ];
    }

    /**
     * Download WorldLabs assets into Laravel storage and return local paths.
     */
    public function downloadAssets(array $assets, string $storagePrefix): array
    {
        $paths = [];

        if ($assets['pano_url']) {
            $paths['world_pano_path'] = $this->downloadTo($assets['pano_url'], $storagePrefix . '/world-pano.webp');
        }

        if ($assets['glb_url']) {
            $paths['world_glb_path'] = $this->downloadTo($assets['glb_url'], $storagePrefix . '/world.glb');
        }

        // Prefer 500k splat, fall back to 150k, then 100k
        $spzPriority = ['500k', '150k', '100k', 'full_res'];
        foreach ($spzPriority as $key) {
            $url = $assets['spz_urls'][$key] ?? null;
            if ($url) {
                $paths['world_spz_path'] = $this->downloadTo($url, $storagePrefix . "/world-{$key}.spz");
                break;
            }
        }

        return $paths;
    }

    // ── private ────────────────────────────────────────────────────────────────

    private function buildRequest(string $imageUrl, string $displayName, ?string $prompt): array
    {
        $worldPrompt = [
            'type'         => 'image',
            'image_prompt' => [
                'source' => 'uri',
                'uri'    => $imageUrl,
            ],
        ];

        if ($prompt) {
            $worldPrompt['text_prompt'] = $prompt;
        }

        return [
            'display_name' => $displayName,
            'model'        => self::MODEL,
            'world_prompt' => $worldPrompt,
        ];
    }

    private function submit(array $request): array
    {
        $response = Http::withHeaders(['WLT-Api-Key' => $this->apiKey])
            ->timeout(30)
            ->post(self::ENDPOINT . '/worlds:generate', $request);

        if (! $response->successful()) {
            throw new RuntimeException(
                'WorldLabs submit failed (' . $response->status() . '): ' . $response->body()
            );
        }

        return $response->json();
    }

    private function poll(string $operationId): array
    {
        $deadline = time() + $this->maxPollSeconds;

        while (time() < $deadline) {
            sleep(self::POLL_DELAY);

            $response = Http::withHeaders(['WLT-Api-Key' => $this->apiKey])
                ->timeout(30)
                ->get(self::ENDPOINT . '/operations/' . $operationId);

            if (! $response->successful()) {
                throw new RuntimeException(
                    'WorldLabs poll failed (' . $response->status() . '): ' . $response->body()
                );
            }

            $body = $response->json();

            if (! empty($body['done'])) {
                return $body;
            }

            Log::debug('WorldLabs polling', ['operation_id' => $operationId, 'status' => $body['metadata']['state'] ?? 'unknown']);
        }

        throw new RuntimeException("WorldLabs generation timed out after {$this->maxPollSeconds}s (operation: {$operationId})");
    }

    private function operationId(array $operation): string
    {
        $id = $operation['operation_id'] ?? $operation['id'] ?? $operation['name'] ?? null;
        if (! $id) {
            throw new RuntimeException('WorldLabs returned no operation ID: ' . json_encode($operation));
        }
        // Strip "operations/" prefix if present
        return preg_replace('#^operations/#', '', (string) $id);
    }

    /**
     * Upload image bytes to fal.ai storage and return the public CDN URL.
     * WorldLabs must be able to fetch the source image — local dev URLs don't work.
     */
    public function uploadImageToFal(string $bytes, string $filename): string
    {
        $falKey = (string) config('services.falai.api_key', '');
        if ($falKey === '') {
            throw new RuntimeException('FAL_AI_KEY required to upload source image for WorldLabs (local dev).');
        }

        $ext      = pathinfo($filename, PATHINFO_EXTENSION) ?: 'webp';
        $mime     = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => 'application/octet-stream',
        };

        // Step 1: get upload URL from fal
        $initRes = Http::withHeaders(['Authorization' => 'Key ' . $falKey])
            ->timeout(30)
            ->post('https://rest.alpha.fal.ai/storage/upload/initiate', [
                'file_name'    => 'worldlabs-source.' . $ext,
                'content_type' => $mime,
            ]);

        if (! $initRes->successful()) {
            throw new RuntimeException('fal storage initiate failed: ' . $initRes->body());
        }

        $upload = $initRes->json();
        $putUrl = $upload['upload_url'] ?? null;
        $cdnUrl = $upload['file_url']   ?? null;

        if (! $putUrl || ! $cdnUrl) {
            throw new RuntimeException('fal storage initiate returned no URLs: ' . $initRes->body());
        }

        // Step 2: PUT the bytes
        $putRes = Http::withHeaders(['Content-Type' => $mime])
            ->timeout(60)
            ->withBody($bytes, $mime)
            ->put($putUrl);

        if (! $putRes->successful()) {
            throw new RuntimeException('fal storage upload failed: ' . $putRes->body());
        }

        return $cdnUrl;
    }

    private function downloadTo(string $url, string $storagePath): string
    {
        $bytes = Http::timeout(120)->get($url)->body();
        Storage::disk('public')->put($storagePath, $bytes);
        return $storagePath;
    }
}
