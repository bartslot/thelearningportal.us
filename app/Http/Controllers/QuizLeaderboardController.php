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
            'integrity' => ['sometimes', 'array'],
            'integrity.avg_ms' => ['sometimes', 'integer', 'min:0'],
            'integrity.rapid_guesses' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'integrity.same_letter_streak' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'integrity.focus_drops' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'answers' => ['sometimes', 'array', 'max:50'],
            'answers.*.question_order' => ['required_with:answers', 'integer', 'min:1', 'max:50'],
            'answers.*.question_text' => ['required_with:answers', 'string', 'max:500'],
            'answers.*.chosen_text' => ['required_with:answers', 'string', 'max:200'],
            'answers.*.correct_text' => ['required_with:answers', 'string', 'max:200'],
            'answers.*.was_correct' => ['required_with:answers', 'boolean'],
            'answers.*.response_ms' => ['nullable', 'integer', 'min:0'],
            'answers.*.asks_ahead' => ['sometimes', 'boolean'],
            'class_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'member_name' => ['required_with:class_code', 'nullable', 'string', 'min:2', 'max:40'],
        ]);

        // DELIBERATE TRADE-OFF: quiz grading happens client-side — correct_index ships to the
        // browser with the questions (see the quiz_questions payload in lesson/player.blade.php).
        // This is a low-stakes, in-class K-12 quiz, so the mitigations are: the score clamp
        // below, engagement/integrity telemetry, and teacher-eyes-only integrity flags.
        // Server-side grading is the v2 path if stakes ever rise (grades, take-home, etc.).
        //
        // Sanity clamp — the client is untrusted; cap at the maximum theoretically earnable.
        $maxScore = $data['total'] * self::MAX_POINTS_PER_QUESTION;
        $score = min((int) $data['score'], $maxScore);
        $correct = min((int) $data['correct'], (int) $data['total']);

        // Hybrid identity: a class code (from a lesson assigned to that classroom)
        // attaches the run to a persistent, account-less roster member.
        $memberId = null;
        if (filled($data['class_code'] ?? null)) {
            $classroom = $lesson->classrooms()
                ->where('join_code', strtoupper(trim($data['class_code'])))
                ->first();
            if (! $classroom) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'class_code' => 'Unknown class code for this lesson.',
                ]);
            }
            $name = \App\Services\Support\NameMatcher::canonical((string) $data['member_name']);
            if (mb_strlen($name) < 2) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'member_name' => 'Enter a real name (first name + first letter of last name).',
                ]);
            }
            $member = \App\Models\ClassroomMember::whereRaw(
                'classroom_id = ? AND lower(display_name) = ?',
                [$classroom->id, mb_strtolower($name)],
            )->first() ?? \App\Models\ClassroomMember::create([
                'classroom_id' => $classroom->id, 'display_name' => $name,
            ]);
            $memberId = $member->id;
        }

        $entry = QuizScore::create([
            'lesson_id' => $lesson->id,
            'nickname' => strip_tags(trim($data['nickname'])),
            'classroom_member_id' => $memberId,
            'score' => $score,
            'correct' => $correct,
            'total' => (int) $data['total'],
            'integrity' => collect($data['integrity'] ?? [])
                ->only(['avg_ms', 'rapid_guesses', 'same_letter_streak', 'focus_drops'])
                ->map(fn ($value) => (int) $value)
                ->all() ?: null,
        ]);

        foreach (array_values($data['answers'] ?? []) as $answer) {
            \App\Models\QuizAnswer::create([
                'quiz_score_id' => $entry->id,
                'question_order' => (int) $answer['question_order'],
                'question_text' => strip_tags((string) $answer['question_text']),
                'chosen_text' => strip_tags((string) $answer['chosen_text']),
                'correct_text' => strip_tags((string) $answer['correct_text']),
                'was_correct' => (bool) $answer['was_correct'],
                'response_ms' => isset($answer['response_ms']) ? (int) $answer['response_ms'] : null,
                'asks_ahead' => (bool) ($answer['asks_ahead'] ?? false),
            ]);
        }

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

    /** @return array{top: list<array<string, mixed>>, players: int} */
    private function leaderboardPayload(Lesson $lesson): array
    {
        // Integrity flags are teacher-eyes only: never on the public board, never to students.
        $isOwningTeacher = (bool) auth()->user()?->canManage($lesson);

        $top = QuizScore::where('lesson_id', $lesson->id)
            ->orderByDesc('score')
            ->orderBy('id')
            ->limit(self::TOP_N)
            ->get(['nickname', 'score', 'correct', 'total', 'integrity'])
            ->map(fn (QuizScore $row) => [
                'nickname' => $row->nickname,
                'score' => $row->score,
                'correct' => $row->correct,
                'total' => $row->total,
            ] + ($isOwningTeacher ? ['integrity' => $row->integrity] : []))
            ->all();

        return ['top' => $top, 'players' => QuizScore::where('lesson_id', $lesson->id)->count()];
    }
}
