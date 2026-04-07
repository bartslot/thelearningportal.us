<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\StudentProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLessonController extends Controller
{
    /**
     * GET /api/v1/student/lessons
     * All published lessons assigned to the student's classrooms.
     */
    public function index(Request $request): JsonResponse
    {
        $student = $request->user();

        $lessons = Lesson::published()
            ->whereHas('classrooms', function ($q) use ($student) {
                $q->whereHas('students', fn($q2) => $q2->where('users.id', $student->id));
            })
            ->with(['quizQuestions:id,lesson_id,order,question,options,points'])
            ->get()
            ->map(fn(Lesson $lesson) => $this->lessonSummary($lesson, $student->id));

        return response()->json(['data' => $lessons]);
    }

    /**
     * GET /api/v1/student/lessons/{id}
     * Full lesson detail including video URL, script, and quiz questions.
     */
    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        // Ensure student has access
        $student = $request->user();
        $hasAccess = $lesson->classrooms()
            ->whereHas('students', fn($q) => $q->where('users.id', $student->id))
            ->exists();

        if (! $hasAccess || $lesson->status->value !== 'published') {
            return response()->json(['message' => 'Lesson not found'], 404);
        }

        $progress = StudentProgress::firstOrNew([
            'student_id' => $student->id,
            'lesson_id'  => $lesson->id,
        ]);

        return response()->json([
            'data' => [
                'id'                => $lesson->id,
                'title'             => $lesson->title,
                'topic'             => $lesson->topic,
                'grade_level'       => $lesson->grade_level,
                'historical_figure' => $lesson->historical_figure,
                'duration_seconds'  => $lesson->duration_seconds,
                'video_url'         => $lesson->videoUrl(),
                'audio_url'         => $lesson->audioUrl(),
                'portrait_url'      => $lesson->portraitUrl(),
                'quiz_questions'    => $lesson->quizQuestions->map(fn($q) => [
                    'id'       => $q->id,
                    'order'    => $q->order,
                    'question' => $q->question,
                    'options'  => $q->options,
                    'points'   => $q->points,
                    // correct_index NOT sent to client until after submission
                ]),
                'my_progress' => [
                    'watch_seconds'     => $progress->watch_seconds,
                    'video_completed'   => $progress->video_completed,
                    'score'             => $progress->score,
                    'max_score'         => $progress->max_score,
                    'completed'         => $progress->completed,
                ],
            ],
        ]);
    }

    private function lessonSummary(Lesson $lesson, int $studentId): array
    {
        $progress = $lesson->studentProgress()->where('student_id', $studentId)->first();

        return [
            'id'                => $lesson->id,
            'title'             => $lesson->title,
            'topic'             => $lesson->topic,
            'grade_level'       => $lesson->grade_level,
            'historical_figure' => $lesson->historical_figure,
            'duration_seconds'  => $lesson->duration_seconds,
            'portrait_url'      => $lesson->portraitUrl(),
            'quiz_count'        => $lesson->quizQuestions->count(),
            'my_progress'       => $progress ? [
                'watch_seconds'   => $progress->watch_seconds,
                'video_completed' => $progress->video_completed,
                'score'           => $progress->score,
                'max_score'       => $progress->max_score,
                'completed'       => $progress->completed,
            ] : null,
        ];
    }

    /**
     * POST /api/v1/student/lessons/{id}/progress
     * Save video progress and/or quiz answers.
     */
    public function saveProgress(Request $request, Lesson $lesson): JsonResponse
    {
        $request->validate([
            'watch_seconds' => 'sometimes|integer|min:0',
            'video_completed' => 'sometimes|boolean',
            'answers'       => 'sometimes|array',   // {question_id: chosen_index}
        ]);

        $student = $request->user();

        $progress = StudentProgress::firstOrNew([
            'student_id' => $student->id,
            'lesson_id'  => $lesson->id,
        ]);

        // Update video progress
        if ($request->has('watch_seconds')) {
            $progress->watch_seconds = max($progress->watch_seconds, $request->watch_seconds);
        }
        if ($request->has('video_completed')) {
            $progress->video_completed = $request->video_completed;
        }

        // Grade quiz if answers submitted
        if ($request->has('answers') && ! $progress->quiz_completed_at) {
            $answers   = $request->answers;
            $questions = $lesson->quizQuestions;
            $score     = 0;
            $maxScore  = 0;

            foreach ($questions as $question) {
                $maxScore += $question->points;
                $chosen = $answers[$question->id] ?? null;
                if ($chosen !== null && $question->isCorrect((int) $chosen)) {
                    $score += $question->points;
                }
            }

            $progress->answers           = $answers;
            $progress->score             = $score;
            $progress->max_score         = $maxScore;
            $progress->quiz_completed_at = now();
        }

        // Mark as fully completed if both video watched and quiz done
        if ($progress->video_completed && $progress->quiz_completed_at && ! $progress->completed) {
            $progress->completed    = true;
            $progress->completed_at = now();
        }

        $progress->save();

        // Return correct answers after quiz submission
        $correctAnswers = null;
        if ($request->has('answers')) {
            $correctAnswers = $lesson->quizQuestions
                ->mapWithKeys(fn($q) => [(string) $q->id => [
                    'correct_index' => $q->correct_index,
                    'explanation'   => $q->explanation,
                ]]);
        }

        return response()->json([
            'data' => [
                'score'           => $progress->score,
                'max_score'       => $progress->max_score,
                'score_percent'   => $progress->scorePercentage(),
                'completed'       => $progress->completed,
                'correct_answers' => $correctAnswers,
            ],
        ]);
    }
}
