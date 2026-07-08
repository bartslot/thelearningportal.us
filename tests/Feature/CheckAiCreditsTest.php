<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LowAiCreditsMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckAiCreditsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.elevenlabs.api_key', 'test-key');
        config()->set('services.alerts.email', 'admin@example.com');
        config()->set('services.alerts.elevenlabs_min_characters', 20000);
    }

    private function fakeSubscription(int $used, int $limit): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/user/subscription' => Http::response([
                'character_count' => $used,
                'character_limit' => $limit,
                'next_character_count_reset_unix' => 1784277066,
                'tier' => 'creator',
            ]),
        ]);
    }

    public function test_sends_email_when_elevenlabs_credits_below_threshold(): void
    {
        Mail::fake();
        $this->fakeSubscription(used: 196000, limit: 204883);

        $this->artisan('tokens:check')
            ->expectsOutputToContain('LOW')
            ->assertExitCode(1);

        Mail::assertSent(LowAiCreditsMail::class, function (LowAiCreditsMail $mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->remaining === 8883;
        });
    }

    public function test_no_email_when_credits_are_sufficient(): void
    {
        Mail::fake();
        $this->fakeSubscription(used: 50000, limit: 204883);

        $this->artisan('tokens:check')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_alerts_when_subscription_api_is_unreachable(): void
    {
        Mail::fake();
        Http::fake([
            'api.elevenlabs.io/v1/user/subscription' => Http::response(null, 500),
        ]);

        $this->artisan('tokens:check')
            ->expectsOutputToContain('unreachable')
            ->assertExitCode(1);
    }
}
