<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ElevenLabsService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('services.elevenlabs.api_key', '');
        $this->baseUrl = config('services.elevenlabs.base_url', 'https://api.elevenlabs.io');
    }

    /** @return array<int, array{id: string, label: string, preview_url: string, gradient_class: string}> */
    public function getVoices(): array
    {
        return Cache::remember('elevenlabs_voices', 3600, function () {
            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/v1/voices");

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('voices', []))
                ->map(fn (array $voice) => [
                    'id'             => $voice['voice_id'],
                    'label'          => $this->buildLabel($voice),
                    'preview_url'    => $voice['preview_url'] ?? '',
                    'gradient_class' => $this->gradientClass($voice['labels'] ?? []),
                ])
                ->values()
                ->all();
        });
    }

    /** @return array{audio: string, alignment: array<mixed>}|null */
    public function generateWithTimestamps(
        string $text,
        string $voiceId,
        float $stability = 0.5,
        float $similarity = 0.75
    ): ?array {
        $response = Http::withHeaders([
            'xi-api-key'   => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(45)->connectTimeout(3)->post(
            "{$this->baseUrl}/v1/text-to-speech/{$voiceId}/with-timestamps",
            [
                'text'           => $text,
                'model_id'       => 'eleven_multilingual_v2',
                'voice_settings' => [
                    'stability'        => $stability,
                    'similarity_boost' => $similarity,
                ],
            ]
        );

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return [
            'audio'     => base64_decode($data['audio_base64'] ?? ''),
            'alignment' => $data['alignment'] ?? [],
        ];
    }

    public function getVoicePreviewUrl(string $voiceId): ?string
    {
        $voices = $this->getVoices();

        foreach ($voices as $voice) {
            if ($voice['id'] === $voiceId) {
                return $voice['preview_url'] ?: null;
            }
        }

        return null;
    }

    private function buildLabel(array $voice): string
    {
        $name   = $voice['name'] ?? '';
        $labels = $voice['labels'] ?? [];
        $accent = $labels['accent'] ?? '';
        $gender = $labels['gender'] ?? '';

        $meta = trim("{$accent} {$gender}");

        return $meta !== '' ? "{$name} · {$meta}" : $name;
    }

    private function gradientClass(array $labels): string
    {
        $accent = strtolower($labels['accent'] ?? '');
        $gender = strtolower($labels['gender'] ?? '');

        if (str_contains($accent, 'british') && $gender === 'male') {
            return 'vg-navy';
        }

        if (str_contains($accent, 'american') || str_contains($accent, 'neutral')) {
            return $gender === 'female' ? 'vg-violet' : 'vg-indigo';
        }

        if ($gender === 'female') {
            return 'vg-violet';
        }

        if (str_contains($accent, 'narration') || str_contains($accent, 'calm')) {
            return 'vg-teal';
        }

        if ($accent !== '' && ! str_contains($accent, 'american') && ! str_contains($accent, 'british')) {
            return 'vg-amber';
        }

        return 'vg-base';
    }
}
