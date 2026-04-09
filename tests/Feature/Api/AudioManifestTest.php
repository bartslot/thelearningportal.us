<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AudioManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_manifest_from_vercel(): void
    {
        Http::fake([
            config('services.vercel_functions.url') . '/api/audio-manifest' => Http::response([
                'duration' => 5.0,
                'samples'  => [['t' => 0.0, 'amp' => 0.1]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audio-manifest?audio_url=' . urlencode('http://example.com/audio.mp3'));

        $response->assertOk()->assertJsonStructure(['duration', 'samples']);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/audio-manifest?audio_url=' . urlencode('http://example.com/audio.mp3'));
        $response->assertUnauthorized();
    }
}
