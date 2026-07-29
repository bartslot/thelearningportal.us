{{-- Lesson handout pack (dompdf) — cover + story pages + quiz answer key.
     dompdf-safe styling only: DejaVu Sans, tables and simple divs, no flexbox,
     no webfonts. Raw hex colors are intentional here (brand: navy + amber). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Handout: {{ $lesson->title ?? $lesson->topic }}</title>
    <style>
        @page { margin: 46px 52px 56px 52px; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5px;
            line-height: 1.55;
            color: #1e293b;
            margin: 0;
        }
        .page-break { page-break-before: always; }

        /* ── Cover ─────────────────────────────────────────────── */
        .cover-band {
            background-color: #0f172a;
            color: #ffffff;
            padding: 28px 32px 24px 32px;
            border-radius: 6px;
        }
        .wordmark {
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #fbbf24;
            margin-bottom: 14px;
        }
        .cover-title { font-size: 24px; font-weight: bold; line-height: 1.25; }
        .cover-subtitle { font-size: 11px; color: #cbd5e1; margin-top: 6px; }
        .accent-rule {
            height: 4px;
            background-color: #fbbf24;
            border-radius: 2px;
            margin: 14px 0 24px 0;
        }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
        .meta-table td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .meta-label {
            width: 130px;
            font-size: 8.5px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #64748b;
        }
        .meta-value { font-size: 11px; }
        .lesson-code { font-weight: bold; letter-spacing: 2px; }

        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #0f172a;
            border-bottom: 2px solid #fbbf24;
            padding-bottom: 5px;
            margin: 0 0 16px 0;
        }
        .objectives { margin: 0; padding-left: 18px; }
        .objectives li { margin-bottom: 6px; }

        /* ── Story pages ───────────────────────────────────────── */
        .scene { page-break-inside: avoid; margin-bottom: 18px; }
        .scene-heading {
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #0f172a;
            border-left: 4px solid #fbbf24;
            padding-left: 8px;
            margin-bottom: 6px;
        }
        .scene-text { text-align: justify; }

        /* ── Answer key ────────────────────────────────────────── */
        .teacher-note {
            font-size: 9px;
            color: #92400e;
            background-color: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 4px;
            padding: 6px 10px;
            margin-bottom: 16px;
        }
        .question { page-break-inside: avoid; margin-bottom: 16px; }
        .question-text { font-weight: bold; margin-bottom: 5px; }
        .options-table { width: 100%; border-collapse: collapse; margin-left: 14px; }
        .options-table td { padding: 2.5px 6px; vertical-align: top; }
        .option-letter { width: 22px; font-weight: bold; color: #64748b; }
        .option-correct { background-color: #fef3c7; }
        .option-correct td { font-weight: bold; color: #0f172a; }
        .correct-mark { color: #b45309; font-weight: bold; }
        .explanation {
            margin: 5px 0 0 20px;
            font-size: 9.5px;
            color: #475569;
            font-style: italic;
        }

        .footer { font-size: 8.5px; color: #94a3b8; margin-top: 30px; }
    </style>
</head>
<body>
    @include('pdf.lesson.cover')

    @if ($scenes->isNotEmpty())
        @include('pdf.lesson.story')
    @endif

    @if ($questions->isNotEmpty())
        @include('pdf.lesson.answer-key')
    @endif
</body>
</html>
