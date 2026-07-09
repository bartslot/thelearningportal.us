@component('emails.layout')
    <h1 style="margin: 0 0 16px 0; font-family: Georgia, 'Times New Roman', serif; font-size: 21px; line-height: 1.3; color: #0f172a;">{{ $provider }} credits are running low</h1>

    <p style="margin: 0 0 16px 0;">Lesson generation on {{ config('app.name') }} may start failing.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 16px 0;">
        <tr>
            <td bgcolor="#f8fafc" style="background-color: #f8fafc; padding: 16px 20px; border-left: 4px solid #fbbf24; font-family: Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.7; color: #0f172a;">
                <strong>Remaining:</strong> {{ number_format($remaining) }} of {{ number_format($limit) }} characters<br>
                <strong>Resets:</strong> {{ $resetsAt?->toDayDateTimeString() ?? 'unknown' }}
            </td>
        </tr>
    </table>

    <p style="margin: 0;">Top up or wait for the reset before generating new lessons &mdash; audio narration jobs will fail once the quota is exhausted.</p>

    @include('emails.partials.button', [
        'url' => 'https://elevenlabs.io/app/subscription',
        'label' => 'View '.$provider.' usage',
    ])

    <p style="margin: 0; color: #64748b; font-size: 13px;">{{ config('app.name') }}</p>
@endcomponent
