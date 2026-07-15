<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Answer sheet') }} — {{ $lesson->title ?? $lesson->topic }}</title>
    <style>
        body { font-family: -apple-system, 'Segoe UI', sans-serif; color: #111; margin: 2rem; }
        .sheet { max-width: 700px; margin: 0 auto; page-break-after: always; }
        .head { display: flex; justify-content: space-between; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 16px; }
        .name-line { border-bottom: 1.5px solid #111; min-width: 260px; display: inline-block; }
        .q { margin: 14px 0; }
        .bubbles { font-size: 22px; letter-spacing: 14px; margin-top: 4px; }
        .footer { margin-top: 24px; font-size: 11px; color: #555; display: flex; justify-content: space-between; }
        .no-print { margin: 0 auto 16px; max-width: 700px; }
        @media print { .no-print { display: none; } body { margin: 0.5cm; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">{{ __('Print') }}</button>
        {{ __('Tip: print one sheet per student. Show the answer options on the digibord.') }}
    </div>
    <div class="sheet">
        <div class="head">
            <div>
                <strong>{{ $lesson->title ?? $lesson->topic }}</strong><br>
                <small>{{ __('Class') }}: ______ &nbsp; {{ __('Date') }}: ______</small>
            </div>
            <div>{{ __('Name (first name + first letter of last name, e.g. "Emma V.")') }}<br><span class="name-line">&nbsp;</span></div>
        </div>
        @foreach ($questions as $i => $question)
            <div class="q">
                <div><strong>{{ $i + 1 }}.</strong> {{ $question->question }}</div>
                <div class="bubbles">Ⓐ Ⓑ Ⓒ Ⓓ</div>
            </div>
        @endforeach
        <div class="footer">
            <span>{{ $lesson->lesson_code }}</span>
            <span>thelearningportal.us · {{ __('sheet') }} v1</span>
        </div>
    </div>
</body>
</html>
