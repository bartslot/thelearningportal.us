@php
    /** @var \App\Models\LessonModule $module */
    /** @var \App\Lessons\Modules\Audience $audience */
    $cfg = $module->config ?? [];
    // Students never see which answer is correct, nor teacher notes (K-8).
    $showAnswers = $audience->isTeacher();
@endphp

<section class="card bg-base-200 lp-bg-card shadow-xl">
    <div class="card-body gap-4">
        <h2 class="card-title">{{ $cfg['question'] ?? __('Question') }}</h2>

        <ul class="grid gap-2">
            @foreach (($cfg['answers'] ?? []) as $answer)
                @php $correct = $showAnswers && ! empty($answer['is_correct']); @endphp
                <li @class([
                    'flex items-center gap-3 rounded-box border p-3',
                    'border-success bg-success/10' => $correct,
                    'border-base-300' => ! $correct,
                ])>
                    <span class="badge">{{ $answer['label'] ?? '' }}</span>
                    <span>{{ $answer['text'] ?? '' }}</span>
                    @if ($correct)
                        <span class="badge badge-success ml-auto">{{ __('Correct') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($showAnswers && ! empty($cfg['teacher_note']))
            <div class="alert">
                <span><strong>{{ __('Teacher note') }}:</strong> {{ $cfg['teacher_note'] }}</span>
            </div>
        @endif
    </div>
</section>
