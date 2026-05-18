<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ElevenLabsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsServiceTest extends TestCase
{
    private ElevenLabsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new ElevenLabsService();
    }

    public function test_get_voices_returns_mapped_array(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    [
                        'voice_id'    => 'abc123',
                        'name'        => 'Alice',
                        'preview_url' => 'https://cdn.elevenlabs.io/alice.mp3',
                        'labels'      => ['accent' => 'american', 'gender' => 'female'],
                    ],
                ],
            ], 200),
        ]);

        $voices = $this->service->getVoices();

        $this->assertCount(1, $voices);
        $this->assertEquals('abc123', $voices[0]['id']);
        $this->assertStringContainsString('Alice', $voices[0]['label']);
        $this->assertEquals('https://cdn.elevenlabs.io/alice.mp3', $voices[0]['preview_url']);
        $this->assertMatchesRegularExpression('/^vg-/', $voices[0]['gradient_class']);
    }

    public function test_get_voices_caches_result(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    [
                        'voice_id'    => 'abc123',
                        'name'        => 'Alice',
                        'preview_url' => 'https://cdn.elevenlabs.io/alice.mp3',
                        'labels'      => ['accent' => 'american', 'gender' => 'female'],
                    ],
                ],
            ], 200),
        ]);

        $this->service->getVoices();
        $this->service->getVoices(); // second call should use cache

        Http::assertSentCount(1);
    }

    public function test_generate_with_timestamps_returns_audio_and_alignment(): void
    {
        $fakeAlignment = [
            'characters'                    => ['H', 'i'],
            'character_start_times_seconds' => [0.0, 0.1],
            'character_end_times_seconds'   => [0.1, 0.2],
        ];

        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*/with-timestamps' => Http::response([
                'audio_base64' => base64_encode('fake-mp3-bytes'),
                'alignment'    => $fakeAlignment,
            ], 200),
        ]);

        $result = $this->service->generateWithTimestamps('Hi', 'voice123');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('audio', $result);
        $this->assertArrayHasKey('alignment', $result);
        $this->assertEquals('fake-mp3-bytes', $result['audio']);
        $this->assertEquals($fakeAlignment, $result['alignment']);
    }

    public function test_generate_with_timestamps_returns_null_on_failure(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*/with-timestamps' => Http::response([], 500),
        ]);

        $result = $this->service->generateWithTimestamps('Hi', 'voice123');

        $this->assertNull($result);
    }

    public function test_get_voice_preview_url_returns_from_cache(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    [
                        'voice_id'    => 'abc123',
                        'name'        => 'Alice',
                        'preview_url' => 'https://cdn.elevenlabs.io/alice.mp3',
                        'labels'      => ['accent' => 'american', 'gender' => 'female'],
                    ],
                ],
            ], 200),
        ]);

        $this->service->getVoices(); // warm cache
        $url = $this->service->getVoicePreviewUrl('abc123');

        $this->assertEquals('https://cdn.elevenlabs.io/alice.mp3', $url);
        Http::assertSentCount(1); // no extra API call
    }
}
