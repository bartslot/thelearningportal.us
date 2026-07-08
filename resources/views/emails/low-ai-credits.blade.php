<x-mail::message>
# {{ $provider }} credits are running low

Lesson generation on {{ config('app.name') }} may start failing.

- **Remaining:** {{ number_format($remaining) }} of {{ number_format($limit) }} characters
- **Resets:** {{ $resetsAt?->toDayDateTimeString() ?? 'unknown' }}

Top up or wait for the reset before generating new lessons — audio narration
jobs will fail once the quota is exhausted.

<x-mail::button :url="'https://elevenlabs.io/app/subscription'">
View {{ $provider }} usage
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
