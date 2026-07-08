<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizScore;

/**
 * Development-only helper that fabricates realistic quiz results so the teacher
 * Results pages can be exercised without real student plays.
 *
 * NEVER wire this into production flows — it writes fake QuizScore/QuizAnswer rows.
 * Callers must gate it behind a local-environment check.
 */
class DevSeeder
{
    /** First name + last initial, mirroring the privacy display convention ("Emma V."). */
    private const NAMES = [
        'Emma V.', 'Liam B.', 'Sophie D.', 'Noah J.', 'Julia M.', 'Lucas K.',
        'Mila P.', 'Daan S.', 'Tess R.', 'Finn H.', 'Sara W.', 'Bram O.',
        'Anna G.', 'Sem T.', 'Nora L.', 'Luuk F.', 'Eva C.', 'Jesse N.',
        'Roos A.', 'Thijs E.', 'Lynn Q.', 'Cas U.', 'Fleur Y.', 'Gijs Z.',
    ];

    private const TEST_CLASS_NAME = 'Test Class 4B';

    /**
     * Fabricate `$count` graded plays for a lesson. Uses the lesson's real quiz
     * questions when present (faithful snapshots); otherwise synthesizes a small
     * question set so the report still populates.
     *
     * @return int number of QuizScore rows created
     */
    public function seedResults(Lesson $lesson, int $count = 12, bool $attachClass = false): int
    {
        $questions = $this->questionSnapshots($lesson);
        if ($questions === []) {
            return 0;
        }

        $classroom = $attachClass ? $this->ensureTestClassroom($lesson) : null;
        $names = $this->pickNames($count);

        $created = 0;
        foreach ($names as $i => $name) {
            $member = $classroom
                ? ClassroomMember::firstOrCreate([
                    'classroom_id' => $classroom->id,
                    'display_name' => $name,
                ])
                : null;

            $skill = $this->skillFor($i, $count);

            $answers = [];
            $correct = 0;
            foreach ($questions as $order => $q) {
                $wasCorrect = ! $q['asks_ahead'] && $this->rollCorrect($skill);
                if ($wasCorrect) {
                    $correct++;
                }
                $answers[] = [
                    'quiz_question_id' => $q['id'],
                    'question_order' => $order + 1,
                    'question_text' => $q['question_text'],
                    'chosen_text' => $wasCorrect ? $q['correct_text'] : $q['distractor'],
                    'correct_text' => $q['correct_text'],
                    'was_correct' => $wasCorrect,
                    'response_ms' => random_int(1500, 18000),
                    'asks_ahead' => $q['asks_ahead'],
                ];
            }

            $score = QuizScore::create([
                'lesson_id' => $lesson->id,
                'nickname' => $name,
                'classroom_member_id' => $member?->id,
                'score' => $correct * 10,
                'correct' => $correct,
                'total' => count($questions),
                'source' => 'web',
                'integrity' => $this->maybeIntegrity($i),
            ]);
            $score->answers()->createMany($answers);
            $created++;
        }

        return $created;
    }

    /** Create (or reuse) a test classroom for this lesson's teacher and attach it to the lesson. */
    public function ensureTestClassroom(Lesson $lesson): Classroom
    {
        $classroom = Classroom::firstOrCreate(
            ['teacher_id' => $lesson->teacher_id, 'name' => self::TEST_CLASS_NAME],
            ['grade_level' => '4', 'is_active' => true],
        );

        if (! $lesson->classrooms()->where('classrooms.id', $classroom->id)->exists()) {
            $lesson->classrooms()->attach($classroom->id, ['assigned_at' => now()]);
        }

        return $classroom;
    }

    /** Delete all fabricated (and real) scores for a lesson; DB cascade removes their answers. */
    public function clearResults(Lesson $lesson): int
    {
        return QuizScore::where('lesson_id', $lesson->id)->delete();
    }

    /**
     * Normalize the lesson's questions into flat snapshot rows.
     *
     * @return list<array{id: ?int, question_text: string, correct_text: string, distractor: string, asks_ahead: bool}>
     */
    private function questionSnapshots(Lesson $lesson): array
    {
        $real = $lesson->quizQuestions()->orderBy('scene_id')->orderBy('order')->get();

        if ($real->isNotEmpty()) {
            return $real->map(function ($q): array {
                $options = is_array($q->options) ? array_values($q->options) : [];
                $correct = (string) ($options[$q->correct_index] ?? '');
                $distractor = (string) (collect($options)
                    ->reject(fn ($opt, $idx) => $idx === $q->correct_index)
                    ->first() ?? $correct);

                return [
                    'id' => $q->id,
                    'question_text' => (string) $q->question,
                    'correct_text' => $correct,
                    'distractor' => $distractor,
                    'asks_ahead' => (bool) $q->asks_ahead,
                ];
            })->all();
        }

        return $this->syntheticQuestions();
    }

    /** @return list<array{id: null, question_text: string, correct_text: string, distractor: string, asks_ahead: bool}> */
    private function syntheticQuestions(): array
    {
        $set = [
            ['In which year did the French Revolution begin?', '1789', '1815'],
            ['Who was the king of France when the Revolution began?', 'Louis XVI', 'Napoleon'],
            ['Which fortress was stormed on 14 July 1789?', 'The Bastille', 'The Louvre'],
            ['What was the revolutionary motto?', 'Liberty, Equality, Fraternity', 'Bread and Land'],
            ['Which assembly issued the Declaration of the Rights of Man?', 'The National Assembly', 'The Roman Senate'],
            ['Who seized power in France after the Revolution?', 'Napoleon Bonaparte', 'Julius Caesar'],
        ];

        return array_map(fn (array $row): array => [
            'id' => null,
            'question_text' => $row[0],
            'correct_text' => $row[1],
            'distractor' => $row[2],
            'asks_ahead' => false,
        ], $set);
    }

    /** @return list<string> */
    private function pickNames(int $count): array
    {
        $pool = self::NAMES;
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            // Wrap the pool with a suffix so members stay uniquely named past 24 students.
            $round = intdiv($i, count($pool));
            $out[] = $pool[$i % count($pool)].($round > 0 ? ' '.($round + 1) : '');
        }

        return $out;
    }

    /**
     * Skill per student, shaped like a real class: most land 60–95%, with a short
     * struggling tail below the 50% "needs help" line. Concave curve keeps the low
     * scores few rather than a flat spread of half-failing students.
     */
    private function skillFor(int $i, int $count): float
    {
        $t = $count <= 1 ? 0.7 : $i / ($count - 1);

        return 0.45 + $t ** 0.55 * 0.5;
    }

    private function rollCorrect(float $skill): bool
    {
        return random_int(0, 100) / 100 <= $skill;
    }

    /** Sprinkle a few integrity flags so the teacher-only telemetry chips are exercised. */
    private function maybeIntegrity(int $i): ?array
    {
        return match ($i % 6) {
            2 => ['rapid_guesses' => random_int(2, 5)],
            4 => ['focus_drops' => random_int(2, 4)],
            5 => ['same_letter_streak' => random_int(4, 6)],
            default => null,
        };
    }
}
