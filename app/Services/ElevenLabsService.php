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

    /** @return array<int, array{id: string, label: string, preview_url: string, gradient_class: string, gender: string}> */
    public function getVoices(): array
    {
        $cached = Cache::get('elevenlabs_voices');
        if (is_array($cached) && count($cached) > 0) {
            return $cached;
        }

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->get("{$this->baseUrl}/v1/voices");

        if (! $response->successful()) {
            return [];
        }

        $voices = collect($response->json('voices', []))
            ->map(fn (array $voice) => [
                'id'             => $voice['voice_id'],
                'label'          => $this->buildLabel($voice),
                'preview_url'    => $voice['preview_url'] ?? '',
                'gradient_class' => $this->gradientClass($voice['labels'] ?? []),
                'gender'         => strtolower($voice['labels']['gender'] ?? ''),
            ])
            ->values()
            ->all();

        if (count($voices) > 0) {
            Cache::put('elevenlabs_voices', $voices, 3600);
        }

        return $voices;
    }

    /** @return array{audio: string, alignment: array<mixed>}|null */
    public function generateWithTimestamps(
        string $text,
        string $voiceId,
        float $stability = 0.5,
        float $similarity = 0.75
    ): ?array {
        try {
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
                \Log::error('[ElevenLabs] with-timestamps HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 300));
                return null;
            }

            $data     = $response->json();
            $audioB64 = $data['audio_base64'] ?? '';

            // Debug: log raw API structure so we can verify the shape in console
            \Log::debug('[ElevenLabs] with-timestamps raw response keys: ' . implode(', ', array_keys($data)));
            \Log::debug('[ElevenLabs] alignment keys: ' . implode(', ', array_keys($data['alignment'] ?? [])));
            \Log::debug('[ElevenLabs] first 3 chars: ' . json_encode(array_slice($data['alignment']['characters'] ?? [], 0, 3)));
            \Log::debug('[ElevenLabs] first 3 starts: ' . json_encode(array_slice($data['alignment']['character_start_times_seconds'] ?? [], 0, 3)));
            \Log::debug('[ElevenLabs] first 3 ends: ' . json_encode(array_slice($data['alignment']['character_end_times_seconds'] ?? [], 0, 3)));

            if ($audioB64 === '') {
                return null;
            }

            // ElevenLabs returns parallel arrays; convert to [{character, start_time, end_time}]
            // so JS speakWithElevenLabsAlignment can iterate directly.
            $raw        = $data['alignment'] ?? [];
            $chars      = $raw['characters']                    ?? [];
            $starts     = $raw['character_start_times_seconds'] ?? [];
            $ends       = $raw['character_end_times_seconds']   ?? [];
            $alignment  = [];
            foreach ($chars as $i => $char) {
                $alignment[] = [
                    'character'  => $char,
                    'start_time' => $starts[$i] ?? 0.0,
                    'end_time'   => $ends[$i]   ?? 0.0,
                ];
            }

            \Log::debug('[ElevenLabs] built ' . count($alignment) . ' alignment entries, sample: ' . json_encode(array_slice($alignment, 0, 3)));

            return [
                'audio'     => base64_decode($audioB64),
                'alignment' => $alignment,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function getVoicePreviewUrl(string $voiceId): ?string
    {
        $voices = $this->getVoices();

        foreach ($voices as $voice) {
            if ($voice['id'] === $voiceId) {
                return $voice['preview_url'] !== '' ? $voice['preview_url'] : null;
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

        if (str_contains($accent, 'narration') || str_contains($accent, 'calm')) {
            return 'vg-teal';
        }

        if (str_contains($accent, 'american') || str_contains($accent, 'neutral')) {
            return $gender === 'female' ? 'vg-violet' : 'vg-indigo';
        }

        if ($gender === 'female') {
            return 'vg-violet';
        }

        if ($accent !== '' && ! str_contains($accent, 'american') && ! str_contains($accent, 'british')) {
            return 'vg-amber';
        }

        return 'vg-base';
    }
}
