<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\LowAiCreditsMail;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BrandedMailTest extends TestCase
{
    private const BRAND_AMBER_HEX = '#fbbf24';

    private const BRAND_NAVY_HEX = '#0f172a';

    private const WORDMARK = 'History&nbsp;Portal';

    private const TAGLINE = 'Where Storytelling Meets Learning.';

    private function renderMail(): string
    {
        $mail = new LowAiCreditsMail(
            provider: 'ElevenLabs',
            remaining: 8883,
            limit: 204883,
            resetsAt: Carbon::createFromTimestamp(1784277066),
        );

        return $mail->render();
    }

    public function test_rendered_mail_contains_brand_wordmark(): void
    {
        $html = $this->renderMail();

        $this->assertStringContainsString(self::WORDMARK, $html);
    }

    public function test_rendered_mail_contains_brand_colors(): void
    {
        $html = $this->renderMail();

        $this->assertStringContainsString(self::BRAND_AMBER_HEX, $html);
        $this->assertStringContainsString(self::BRAND_NAVY_HEX, $html);
    }

    public function test_rendered_mail_contains_tagline(): void
    {
        $html = $this->renderMail();

        $this->assertStringContainsString(self::TAGLINE, $html);
    }

    public function test_rendered_mail_contains_lesson_credit_details(): void
    {
        $html = $this->renderMail();

        $this->assertStringContainsString('ElevenLabs credits are running low', $html);
        $this->assertStringContainsString('8,883', $html);
        $this->assertStringContainsString('204,883', $html);
        $this->assertStringContainsString('https://elevenlabs.io/app/subscription', $html);
    }

    public function test_rendered_mail_has_no_unresolved_blade_tags(): void
    {
        $html = $this->renderMail();

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
        $this->assertStringNotContainsString('@component', $html);
        $this->assertStringNotContainsString('@include', $html);
        $this->assertStringNotContainsString('@endcomponent', $html);
    }
}
