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

    /** The page background. Every email sits on this. */
    private const BRAND_DEEP_NAVY_HEX = '#040B1A';

    /** The brand mark is an image now, not a text wordmark. */
    private const LOGO_FILE = 'assets/email/logo-history-portal.png';

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

    public function test_rendered_mail_shows_the_logo(): void
    {
        $html = $this->renderMail();

        $this->assertStringContainsString(self::LOGO_FILE, $html);
    }

    public function test_the_logo_is_a_png_that_actually_exists(): void
    {
        // Two ways this breaks silently and is only ever noticed by a recipient:
        //
        // An SVG, because Gmail, Outlook and Yahoo all refuse to render one, so the site's own
        // logo.svg would be a broken image for most of the people receiving this.
        //
        // A file that is not deployed, because the mark is fetched over the internet from whatever
        // asset() points at, and a 404 is a hole where the branding should be.
        $this->assertStringEndsWith('.png', self::LOGO_FILE, 'an SVG logo does not render in most mail clients');
        $this->assertFileExists(public_path(self::LOGO_FILE));
    }

    public function test_the_logo_is_referenced_absolutely(): void
    {
        // A relative src resolves against nothing inside a mail client. It must carry the scheme
        // and host, which is what asset() does and what APP_URL has to be right for.
        $html = $this->renderMail();

        $this->assertMatchesRegularExpression(
            '~src="https?://[^"]+/'.preg_quote(self::LOGO_FILE, '~').'"~',
            $html,
            'the logo is not an absolute URL, so it is a broken image in every mail client',
        );
    }

    public function test_rendered_mail_contains_brand_colors(): void
    {
        $html = $this->renderMail();

        $this->assertStringContainsString(self::BRAND_DEEP_NAVY_HEX, $html);
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
