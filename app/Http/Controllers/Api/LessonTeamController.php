<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\LessonStatus;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonTeamController extends Controller
{
    public function index(string $lessonCode): JsonResponse
    {
        $lesson = Lesson::where('lesson_code', strtoupper($lessonCode))
            ->where('status', LessonStatus::Published)
            ->firstOrFail();

        $teams = LessonTeam::where('lesson_id', $lesson->id)
            ->with(['members.student:id,name'])
            ->get()
            ->map(fn (LessonTeam $t) => $this->teamPayload($t));

        return response()->json([
            'teams'            => $teams,
            'lesson_code'      => $lesson->lesson_code,
            'strategy_game_id' => $lesson->strategy_game_id,
        ]);
    }

    public function store(Request $request, string $lessonCode): JsonResponse
    {
        $lesson = Lesson::where('lesson_code', strtoupper($lessonCode))
            ->where('status', LessonStatus::Published)
            ->firstOrFail();

        $validated = $request->validate([
            'team_count' => 'sometimes|integer|min:2|max:10',
        ]);

        $teams = LessonTeam::autoForm($lesson, $validated['team_count'] ?? 4);

        return response()->json([
            'teams' => $teams->map(fn (LessonTeam $t) => $this->teamPayload($t)),
        ], 201);
    }

    /**
     * Team + member names originate from teacher-entered rosters — strip any markup
     * at the API boundary (defense-in-depth alongside the player's textContent rendering).
     *
     * @return array{id: int, name: string, color: string, members: \Illuminate\Support\Collection}
     */
    private function teamPayload(LessonTeam $team): array
    {
        return [
            'id'      => $team->id,
            'name'    => strip_tags((string) $team->name),
            'color'   => $team->color,
            'members' => $team->members->map(fn ($m) => ['name' => strip_tags((string) $m->name)]),
        ];
    }
}
