<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lesson-scoped quiz leaderboard (Kahoot-style). Public like the player itself —
 * players are anonymous nicknames, throttled per route to keep spam boring.
 */
class QuizLeaderboardController extends Controller
{
    private const TOP_N = 10;

    /** Max points per question: 10 correct + 5 streak bonus. Anything above is a forged payload. */
    private const MAX_POINTS_PER_QUESTION = 15;

    public function index(string $lessonCode): JsonResponse
    {
        $lesson = $this->playableLesson($lessonCode);

        return response()->json($this->leaderboardPayload($lesson));
    }

    public function store(Request $request, string $lessonCode): JsonResponse
    {
        $lesson = $this->playableLesson($lessonCode);

        $data = $request->validate([
            'nickname' => ['required', 'string', 'min:2', 'max:24'],
            'score' => ['required', 'integer', 'min:0'],
            'correct' => ['required', 'integer', 'min:0', 'max:50'],
            'total' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        // Sanity clamp — the client is untrusted; cap at the maximum theoretically earnable.
        $maxScore = $data['total'] * self::MAX_POINTS_PER_QUESTION;
        $score = min((int) $data['score'], $maxScore);
        $correct = min((int) $data['correct'], (int) $data['total']);

        $entry = QuizScore::create([
            'lesson_id' => $lesson->id,
            'nickname' => strip_tags(trim($data['nickname'])),
            'score' => $score,
            'correct' => $correct,
            'total' => (int) $data['total'],
        ]);

        // Rank = players strictly ahead + 1 (ties share the better rank; earlier entry wins).
        $rank = QuizScore::where('lesson_id', $lesson->id)
            ->where(fn ($query) => $query
                ->where('score', '>', $entry->score)
                ->orWhere(fn ($tie) => $tie->where('score', $entry->score)->where('id', '<', $entry->id)))
            ->count() + 1;

        return response()->json($this->leaderboardPayload($lesson) + [
            'rank' => $rank,
            'entry_id' => $entry->id,
        ], 201);
    }

    private function playableLesson(string $lessonCode): Lesson
    {
        return Lesson::where('lesson_code', strtoupper($lessonCode))
            ->whereIn('status', [LessonStatus::Published, LessonStatus::Previewable, LessonStatus::Ready])
            ->firstOrFail();
    }

    /** @return array{top: list<array{nickname: string, score: int, correct: int, total: int}>, players: int} */
    private function leaderboardPayload(Lesson $lesson): array
    {
        $top = QuizScore::where('lesson_id', $lesson->id)
            ->orderByDesc('score')
            ->orderBy('id')
            ->limit(self::TOP_N)
            ->get(['nickname', 'score', 'correct', 'total'])
            ->map(fn (QuizScore $row) => [
                'nickname' => $row->nickname,
                'score' => $row->score,
                'correct' => $row->correct,
                'total' => $row->total,
            ])
            ->all();

        return ['top' => $top, 'players' => QuizScore::where('lesson_id', $lesson->id)->count()];
    }
}
