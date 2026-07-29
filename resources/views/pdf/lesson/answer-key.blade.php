{{-- Handout final page(s) — teacher-only quiz answer key --}}
<div class="page-break"></div>
<h2 class="section-title">{{ __('Quiz answer key') }}</h2>
<div class="teacher-note">{{ __('Teacher copy. This contains the correct answers, so do not hand it out to students.') }}</div>

@foreach ($questions as $question)
    <div class="question">
        <div class="question-text">{{ $loop->iteration }}. {{ $question->question }}</div>
        <table class="options-table">
            @foreach ($question->options as $index => $option)
                <tr class="{{ $index === $question->correct_index ? 'option-correct' : '' }}">
                    <td class="option-letter">{{ ['A', 'B', 'C', 'D', 'E', 'F'][$index] ?? '?' }}</td>
                    <td>
                        {{ $option }}
                        @if ($index === $question->correct_index)
                            <span class="correct-mark">&#10003; {{ __('correct') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        @if ($question->explanation)
            <div class="explanation">{{ $question->explanation }}</div>
        @endif
    </div>
@endforeach
