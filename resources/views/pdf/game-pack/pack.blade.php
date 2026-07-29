{{-- Printed game pack (spelpakket) — dompdf. Tables & simple divs only, no flexbox,
     no webfonts. Raw hex is intentional (brand: navy #0f172a + amber #fbbf24). --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Spelpakket: {{ $lesson->title ?? $lesson->topic }}</title>
    <style>
        @page { margin: 40px 44px 44px 44px; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            line-height: 1.5;
            color: #1e293b;
            margin: 0;
        }
        .page-break { page-break-before: always; }

        /* ── Sheet header ──────────────────────────────────────── */
        .sheet-band {
            background-color: #0f172a;
            color: #ffffff;
            padding: 16px 20px;
            border-radius: 6px;
            margin-bottom: 18px;
        }
        .wordmark {
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #fbbf24;
            margin-bottom: 8px;
        }
        .sheet-title { font-size: 18px; font-weight: bold; }
        .sheet-note { font-size: 9px; color: #cbd5e1; margin-top: 4px; }

        .cut-note {
            font-size: 8.5px;
            color: #92400e;
            background-color: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 4px;
            padding: 5px 9px;
            margin-bottom: 14px;
        }

        /* ── Role cards (2 per A4 page) ────────────────────────── */
        .role-card {
            border: 2px dashed #94a3b8;
            border-radius: 8px;
            padding: 20px 22px;
            height: 300px;
            margin-bottom: 18px;
            page-break-inside: avoid;
        }
        .role-eyebrow {
            font-size: 8px; font-weight: bold; letter-spacing: 2px;
            text-transform: uppercase; color: #b45309; margin-bottom: 8px;
        }
        .role-title { font-size: 26px; font-weight: bold; color: #0f172a; margin-bottom: 10px; }
        .role-flavor { font-size: 13px; font-style: italic; color: #475569; margin-bottom: 18px; }
        .role-power-label {
            font-size: 8px; font-weight: bold; letter-spacing: 1.5px;
            text-transform: uppercase; color: #64748b; margin-bottom: 4px;
        }
        .role-power {
            font-size: 13px; color: #0f172a;
            border-left: 4px solid #fbbf24; padding-left: 10px;
        }

        /* ── Tokens ────────────────────────────────────────────── */
        .token-table { border-collapse: collapse; width: 100%; }
        .token-table td {
            border: 1px dashed #94a3b8;
            width: 25%;
            height: 84px;
            text-align: center;
            vertical-align: middle;
        }
        .token-icon { font-size: 22px; }
        .token-label {
            font-size: 8px; font-weight: bold; letter-spacing: 1px;
            text-transform: uppercase; color: #475569; margin-top: 3px;
        }

        /* ── Event cards ───────────────────────────────────────── */
        .event-card {
            border: 2px solid #0f172a;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 14px;
            page-break-inside: avoid;
        }
        .event-eyebrow {
            font-size: 8px; font-weight: bold; letter-spacing: 2px;
            text-transform: uppercase; color: #b45309; margin-bottom: 6px;
        }
        .event-question { font-size: 12px; margin-bottom: 12px; text-align: justify; }
        .event-option {
            border: 1px solid #cbd5e1; border-radius: 6px;
            padding: 8px 11px; margin-bottom: 7px; font-size: 11px;
        }
        .event-option-letter { font-weight: bold; color: #b45309; }

        /* ── Meter poster ──────────────────────────────────────── */
        .meter-block { margin-bottom: 24px; page-break-inside: avoid; }
        .meter-head { font-size: 17px; font-weight: bold; color: #0f172a; margin-bottom: 8px; }
        .meter-track { border-collapse: collapse; width: 100%; }
        .meter-track td {
            border: 1px solid #0f172a;
            height: 40px;
            text-align: center;
            vertical-align: middle;
            font-size: 10px;
            color: #64748b;
        }
        .meter-start { background-color: #fbbf24; color: #0f172a; font-weight: bold; }

        /* ── Team badges ───────────────────────────────────────── */
        .badge-table { border-collapse: separate; border-spacing: 10px; width: 100%; }
        .badge-cell {
            width: 33%;
            border-radius: 10px;
            padding: 22px 14px;
            text-align: center;
            color: #ffffff;
        }
        .badge-team-label {
            font-size: 8px; font-weight: bold; letter-spacing: 2px;
            text-transform: uppercase; opacity: 0.85;
        }
        .badge-team-name { font-size: 22px; font-weight: bold; margin-top: 6px; }

        .footer { font-size: 8px; color: #94a3b8; margin-top: 22px; }
        .empty-note { font-size: 10px; color: #94a3b8; font-style: italic; }
    </style>
</head>
<body>
    @include('pdf.game-pack.role-cards')
    @include('pdf.game-pack.tokens')
    @include('pdf.game-pack.event-cards')
    @include('pdf.game-pack.meter-poster')
    @include('pdf.game-pack.team-badges')
</body>
</html>
