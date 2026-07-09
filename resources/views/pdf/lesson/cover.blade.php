{{-- Handout page 1 — branded cover / lesson summary --}}
<div class="cover-band">
    <div class="wordmark">History Portal</div>
    <div class="cover-title">{{ $lesson->title ?? $lesson->topic }}</div>
    <div class="cover-subtitle">{{ __('Lesson handout') }}</div>
</div>
<div class="accent-rule"></div>

<table class="meta-table">
    <tr>
        <td class="meta-label">{{ __('Topic') }}</td>
        <td class="meta-value">{{ $lesson->topic }}</td>
    </tr>
    @if ($lesson->grade_level)
        <tr>
            <td class="meta-label">{{ __('Grade') }}</td>
            <td class="meta-value">{{ $lesson->grade_level }}</td>
        </tr>
    @endif
    <tr>
        <td class="meta-label">{{ __('Lesson code') }}</td>
        <td class="meta-value lesson-code">{{ $lesson->lesson_code }}</td>
    </tr>
    <tr>
        <td class="meta-label">{{ __('Date') }}</td>
        <td class="meta-value">{{ $generatedAt->format('j F Y') }}</td>
    </tr>
</table>

@if (count($objectives) > 0)
    <h2 class="section-title">{{ __('Learning objectives') }}</h2>
    <ul class="objectives">
        @foreach ($objectives as $objective)
            <li>{{ $objective }}</li>
        @endforeach
    </ul>
@endif

@if ($lesson->sourceAttribution())
    <div class="footer">{{ $lesson->sourceAttribution() }}</div>
@endif
