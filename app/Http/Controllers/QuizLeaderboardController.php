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
            // Which quiz scene this run belongs to, so several quizzes in one lesson add up for the
            // same player instead of inventing a new one each time. Optional: older clients omit it.
            'quiz_scene_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        // Only a quiz scene of THIS lesson counts. A foreign id would let one lesson's board be
        // written from another's, and an unknown id would silently create a phantom entry.
        $sceneId = filled($data['quiz_scene_id'] ?? null)
            ? $lesson->scenes()->whereKey((int) $data['quiz_scene_id'])->value('id')
            : null;

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

        $nickname = strip_tags(trim($data['nickname']));

        // ONE ROW PER PLAYER PER QUIZ SCENE.
        //
        // A lesson holds several quiz scenes, and QuizOverlay resets its running score to zero at
        // each one, so the same child submits two or three times under the same name. Creating a row
        // per submission made them two or three PLAYERS with partial scores, and the board read like
        // strangers had each done half the work.
        //
        // Keyed on the scene, so answering the second quiz ADDS a row while retrying the first
        // REPLACES its own — otherwise replaying a lesson would inflate a score without limit, which
        // in a classroom competition is the same as breaking it. The board then sums the rows.
        //
        // A submission with no scene (older client, or a lesson whose quiz is not scene-scoped)
        // falls back to one row for the whole lesson, which is the behaviour those clients expect.
        $entry = QuizScore::updateOrCreate(
            [
                'lesson_id' => $lesson->id,
                'quiz_scene_id' => $sceneId,
            ] + $this->playerKey($nickname, $memberId),
            [
                'nickname' => $nickname,
                'classroom_member_id' => $memberId,
                'score' => $score,
                'correct' => $correct,
                'total' => (int) $data['total'],
                'integrity' => collect($data['integrity'] ?? [])
                    ->only(['avg_ms', 'rapid_guesses', 'same_letter_streak', 'focus_drops'])
                    ->map(fn ($value) => (int) $value)
                    ->all() ?: null,
            ],
        );

        // A retry replaces this scene's answers as well as its score, or the teacher's report would
        // show both attempts as if the child had answered twice as many questions.
        $entry->answers()->delete();

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

        // Rank against TOTALS, not against this one quiz. Ranking a partial score among other
        // players' totals put a child near the bottom the moment they finished the first quiz.
        $totals = $this->playerTotals($lesson);
        $mine = $this->identityFor($nickname, $memberId);
        $myTotal = $totals[$mine]['score'] ?? $entry->score;
        $rank = collect($totals)->filter(fn ($row, $key) => $row['score'] > $myTotal)->count() + 1;

        return response()->json($this->leaderboardPayload($lesson) + [
            'rank' => $rank,
            'entry_id' => $entry->id,
            'total_score' => $myTotal,
        ], 201);
    }

    /**
     * How a player is recognised again on their next quiz.
     *
     * A roster member is the same person whatever they type; otherwise it is the nickname, matched
     * case-insensitively, because a child who types "Sanne" and then "sanne" is one child and cannot
     * see or fix the difference.
     *
     * @return array<string,mixed>
     */
    private function playerKey(string $nickname, ?int $memberId): array
    {
        return $memberId !== null
            ? ['classroom_member_id' => $memberId]
            : ['nickname' => $nickname];
    }

    private function identityFor(?string $nickname, ?int $memberId): string
    {
        return $memberId !== null ? "member:{$memberId}" : 'nick:'.mb_strtolower(trim((string) $nickname));
    }

    /**
     * Each player's summed score across every quiz scene in the lesson.
     *
     * Summed at read time rather than kept in a running column: the per-scene rows stay intact for
     * the teacher's report, which reads answers off individual runs and would lose which quiz a
     * mistake belonged to if they were collapsed.
     *
     * @return array<string, array{nickname:string, score:int, correct:int, total:int, first_id:int, integrity:mixed}>
     */
    private function playerTotals(Lesson $lesson): array
    {
        return QuizScore::where('lesson_id', $lesson->id)
            ->orderBy('id')
            ->get(['id', 'nickname', 'classroom_member_id', 'score', 'correct', 'total', 'integrity'])
            ->reduce(function (array $carry, QuizScore $row): array {
                $key = $this->identityFor($row->nickname, $row->classroom_member_id);

                $carry[$key] ??= [
                    'nickname' => $row->nickname,
                    'score' => 0, 'correct' => 0, 'total' => 0,
                    'first_id' => $row->id,
                    'integrity' => null,
                ];
                $carry[$key]['score'] += (int) $row->score;
                $carry[$key]['correct'] += (int) $row->correct;
                $carry[$key]['total'] += (int) $row->total;
                // Keep the worst-looking integrity reading rather than the newest: a teacher wants
                // to know a run was odd, and the last quiz being clean does not undo the first.
                $carry[$key]['integrity'] = $row->integrity ?? $carry[$key]['integrity'];

                return $carry;
            }, []);
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

        // Totals per player, not per submission. A lesson with two quiz scenes used to list the same
        // child twice with half a score each.
        $totals = $this->playerTotals($lesson);

        $top = collect($totals)
            ->sortBy([['score', 'desc'], ['first_id', 'asc']])   // ties go to whoever got there first
            ->take(self::TOP_N)
            ->values()
            ->map(fn (array $row) => [
                'nickname' => $row['nickname'],
                'score' => $row['score'],
                'correct' => $row['correct'],
                'total' => $row['total'],
            ] + ($isOwningTeacher ? ['integrity' => $row['integrity']] : []))
            ->all();

        // Players, not runs. Counting rows told a teacher eleven children had played when six had.
        return ['top' => $top, 'players' => count($totals)];
    }
}
