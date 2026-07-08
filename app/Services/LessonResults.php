<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\QuizAnswer;
use App\Models\QuizScore;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * All report math for the teacher results pages. Pure reads over quiz_scores +
 * quiz_answers SNAPSHOTS (never joins live quiz_questions — they get regenerated).
 * Needs-help rule (Kahoot-inspired): correct-rate < 50% on non-asks-ahead answers.
 */
final class LessonResults
{
    public const NEEDS_HELP_BELOW_PCT = 50;

    public const DIFFICULT_BELOW_PCT = 50;

    public function __construct(
        private readonly Lesson $lesson,
        private readonly ?int $classroomId = null,
        private readonly ?CarbonInterface $from = null,
        private readonly ?CarbonInterface $to = null,
    ) {}

    /** @return Collection<int, QuizScore> */
    private function scores(): Collection
    {
        return QuizScore::with(['answers', 'member'])
            ->where('lesson_id', $this->lesson->id)
            ->when($this->classroomId, fn ($q) => $q->whereHas(
                'member', fn ($m) => $m->where('classroom_id', $this->classroomId),
            ))
            ->when($this->from, fn ($q) => $q->where('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('created_at', '<=', $this->to))
            ->orderByDesc('score')->orderBy('id')
            ->get();
    }

    /** Correct-rate (0-100, rounded) over non-asks-ahead answers; null when no gradable answers. */
    private function gradableRate(QuizScore $score): ?int
    {
        $gradable = $score->answers->where('asks_ahead', false);
        if ($gradable->isEmpty()) {
            // Legacy rows without answer snapshots: fall back to the aggregate.
            return $score->total > 0 ? (int) round($score->correct / $score->total * 100) : null;
        }

        return (int) round($gradable->where('was_correct', true)->count() / $gradable->count() * 100);
    }

    /** @return array{players: int, avg_correct_pct: int, needs_help: list<array{name: string, score_id: int, pct: int}>, leaderboard: list<array<string, mixed>>} */
    public function overview(): array
    {
        $scores = $this->scores();

        $gradable = $scores->flatMap(fn (QuizScore $s) => $s->answers->where('asks_ahead', false));
        $avg = $gradable->isNotEmpty()
            ? (int) round($gradable->where('was_correct', true)->count() / $gradable->count() * 100)
            : 0;

        $needsHelp = $scores
            ->map(fn (QuizScore $s) => ['name' => $this->nameFor($s), 'score_id' => $s->id, 'pct' => $this->gradableRate($s)])
            ->filter(fn (array $row) => $row['pct'] !== null && $row['pct'] < self::NEEDS_HELP_BELOW_PCT)
            ->values()->all();

        $leaderboard = $scores->map(fn (QuizScore $s) => [
            'score_id' => $s->id,
            'name' => $this->nameFor($s),
            'score' => $s->score,
            'correct' => $s->correct,
            'total' => $s->total,
            'source' => $s->source,
            'integrity' => $s->integrity,
            'pct' => $this->gradableRate($s),
            'needs_help' => ($this->gradableRate($s) ?? 100) < self::NEEDS_HELP_BELOW_PCT,
            'played_at' => $s->created_at,
        ])->values()->all();

        return [
            'players' => $scores->count(),
            'avg_correct_pct' => $avg,
            'needs_help' => $needsHelp,
            'leaderboard' => $leaderboard,
        ];
    }

    /** @return list<array{question_text: string, correct_pct: int, answered: int, asks_ahead: bool, missed_by: list<string>}> */
    public function difficultQuestions(): array
    {
        return collect($this->questionBreakdown())
            ->filter(fn (array $q) => $q['correct_pct'] < self::DIFFICULT_BELOW_PCT)
            ->sortBy('correct_pct')->values()->all();
    }

    /** Every question (snapshot-grouped), ranked worst-first, with answer distribution. */
    public function questionBreakdown(): array
    {
        $answers = QuizAnswer::whereIn(
            'quiz_score_id', $this->scores()->pluck('id'),
        )->get()->groupBy('question_text');

        $scoresById = $this->scores()->keyBy('id');

        return $answers->map(function (Collection $group, string $questionText) use ($scoresById) {
            $distribution = $group->groupBy('chosen_text')
                ->map(fn (Collection $picks) => $picks->count())
                ->sortDesc()->all();

            return [
                'question_text' => $questionText,
                'answered' => $group->count(),
                'correct_pct' => (int) round($group->where('was_correct', true)->count() / max(1, $group->count()) * 100),
                'asks_ahead' => (bool) $group->first()->asks_ahead,
                'correct_text' => $group->first()->correct_text,
                'distribution' => $distribution,
                'missed_by' => $group->where('was_correct', false)
                    ->map(fn (QuizAnswer $a) => $this->nameFor($scoresById[$a->quiz_score_id] ?? null))
                    ->filter()->unique()->values()->all(),
            ];
        })->sortBy('correct_pct')->values()->all();
    }

    /** @return list<array{score_id: int, name: string, pct: ?int, needs_help: bool, source: string, integrity: ?array, played_at: mixed}> */
    public function players(): array
    {
        return $this->scores()->map(fn (QuizScore $s) => [
            'score_id' => $s->id,
            'name' => $this->nameFor($s),
            'score' => $s->score,
            'correct' => $s->correct,
            'total' => $s->total,
            'pct' => $this->gradableRate($s),
            'needs_help' => ($this->gradableRate($s) ?? 100) < self::NEEDS_HELP_BELOW_PCT,
            'source' => $s->source,
            'integrity' => $s->integrity,
            'played_at' => $s->created_at,
        ])->values()->all();
    }

    /** Per-question drill-down for one run. @return list<array<string, mixed>> */
    public function drilldown(int $scoreId): array
    {
        $score = QuizScore::with('answers')->where('lesson_id', $this->lesson->id)->findOrFail($scoreId);

        return $score->answers->map(fn (QuizAnswer $a) => [
            'question_order' => $a->question_order,
            'question_text' => $a->question_text,
            'chosen_text' => $a->chosen_text,
            'correct_text' => $a->correct_text,
            'was_correct' => $a->was_correct,
            'response_ms' => $a->response_ms,
            'asks_ahead' => $a->asks_ahead,
        ])->all();
    }

    private function nameFor(?QuizScore $score): ?string
    {
        return $score?->member?->display_name ?? $score?->nickname;
    }
}
