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
            ->map(fn (LessonTeam $t) => [
                'id'      => $t->id,
                'name'    => $t->name,
                'color'   => $t->color,
                'members' => $t->members->map(fn ($m) => ['name' => $m->name]),
            ]);

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
            'teams' => $teams->map(fn (LessonTeam $t) => [
                'id'      => $t->id,
                'name'    => $t->name,
                'color'   => $t->color,
                'members' => $t->members->map(fn ($m) => ['name' => $m->name]),
            ]),
        ], 201);
    }
}
