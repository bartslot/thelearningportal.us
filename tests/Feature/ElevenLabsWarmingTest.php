<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Services\ElevenLabsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The voice-list warm-up is a background API call the app makes on its own, so the things worth
 * pinning down are when it DOESN'T happen: when another provider is narrating, and when the API
 * is failing. Both were once endless — a dead ElevenLabs left the cache empty, so the app kept
 * queueing warm jobs, and with no worker draining them they simply accumulated.
 */
class ElevenLabsWarmingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(ElevenLabsService::VOICES_KEY);
        Cache::forget(ElevenLabsService::FAILED_KEY);
    }

    private function narratesWithElevenLabs(): bool
    {
        $method = new \ReflectionMethod(AppServiceProvider::class, 'narratesWithElevenLabs');

        return $method->invoke(new AppServiceProvider($this->app));
    }

    public function test_a_failed_fetch_backs_off_instead_of_asking_again(): void
    {
        Http::fake(['*/v1/voices' => Http::response('nope', 500)]);

        $service = new ElevenLabsService;

        $this->assertSame([], $service->getVoices());
        $this->assertTrue(Cache::has(ElevenLabsService::FAILED_KEY), 'the failure should be remembered');

        // Second call inside the backoff must not reach the API at all.
        $this->assertSame([], $service->getVoices());
        Http::assertSentCount(1);
    }

    public function test_a_successful_but_empty_roster_also_backs_off(): void
    {
        Http::fake(['*/v1/voices' => Http::response(['voices' => []], 200)]);

        $this->assertSame([], (new ElevenLabsService)->getVoices());
        $this->assertTrue(Cache::has(ElevenLabsService::FAILED_KEY), 'nothing was cached, so nothing should re-ask');
    }

    public function test_a_good_fetch_caches_the_voices(): void
    {
        Http::fake(['*/v1/voices' => Http::response([
            'voices' => [['voice_id' => 'abc', 'name' => 'Ollie', 'labels' => ['gender' => 'male']]],
        ], 200)]);

        $voices = (new ElevenLabsService)->getVoices();

        $this->assertCount(1, $voices);
        $this->assertSame('abc', $voices[0]['id']);
        $this->assertTrue(Cache::has(ElevenLabsService::VOICES_KEY));
        $this->assertFalse(Cache::has(ElevenLabsService::FAILED_KEY));
    }

    public function test_the_backoff_short_circuits_before_the_api_is_called(): void
    {
        Cache::put(ElevenLabsService::FAILED_KEY, true, 900);
        Http::fake();

        $this->assertSame([], (new ElevenLabsService)->getVoices());
        Http::assertNothingSent();
    }

    public function test_no_warming_while_another_provider_narrates(): void
    {
        config(['services.tts.provider_override' => 'azure']);
        $this->assertFalse($this->narratesWithElevenLabs());
    }

    public function test_warming_stays_on_when_elevenlabs_narrates(): void
    {
        config(['services.tts.provider_override' => null]);
        $this->assertTrue($this->narratesWithElevenLabs(), 'no override means the default provider');

        config(['services.tts.provider_override' => 'ElevenLabs']);
        $this->assertTrue($this->narratesWithElevenLabs(), 'the check should not care about casing');
    }
}
