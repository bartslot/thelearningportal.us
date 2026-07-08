<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\LowAiCreditsMail;
use App\Services\ElevenLabsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CheckAiCredits extends Command
{
    protected $signature = 'tokens:check';

    protected $description = 'Check AI provider credit balances and email the admin when they run low';

    public function handle(ElevenLabsService $elevenLabs): int
    {
        $adminEmail = config('services.alerts.email');

        if (! is_string($adminEmail) || $adminEmail === '') {
            $this->error('services.alerts.email is not configured — cannot send alerts.');

            return self::FAILURE;
        }

        $subscription = $elevenLabs->getSubscription();

        if ($subscription === null) {
            $this->error('ElevenLabs subscription API unreachable — cannot verify credits.');

            return self::FAILURE;
        }

        $threshold = (int) config('services.alerts.elevenlabs_min_characters');

        $this->info(sprintf(
            'ElevenLabs: %s of %s characters remaining (alert below %s), resets %s.',
            number_format($subscription['remaining']),
            number_format($subscription['limit']),
            number_format($threshold),
            $subscription['resets_at']->toDayDateTimeString(),
        ));

        if ($subscription['remaining'] >= $threshold) {
            return self::SUCCESS;
        }

        $this->warn("ElevenLabs credits LOW — emailing {$adminEmail}.");

        Mail::to($adminEmail)->send(new LowAiCreditsMail(
            provider: 'ElevenLabs',
            remaining: $subscription['remaining'],
            limit: $subscription['limit'],
            resetsAt: $subscription['resets_at'],
        ));

        return self::FAILURE;
    }
}
