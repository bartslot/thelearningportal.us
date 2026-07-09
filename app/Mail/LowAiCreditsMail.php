<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class LowAiCreditsMail extends Mailable
{
    public function __construct(
        public readonly string $provider,
        public readonly int $remaining,
        public readonly int $limit,
        public readonly ?Carbon $resetsAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "⚠️ {$this->provider} credits low — {$this->remaining} remaining",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.low-ai-credits',
        );
    }
}
