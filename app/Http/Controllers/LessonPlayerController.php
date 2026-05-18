<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LessonPlayerController extends Controller
{
    public function __invoke(Request $request, string $lessonCode): View
    {
        $lesson = Lesson::where('lesson_code', strtoupper($lessonCode))
            ->where('status', LessonStatus::Published)
            ->with(['strategyGame', 'avatar'])
            ->first();

        if (! $lesson) {
            abort(404, 'Lesson not found or not yet published.');
        }

        // Auto-match a strategy game if none assigned
        if (! $lesson->strategy_game_id) {
            $game = \App\Models\StrategyGame::matchForLesson($lesson);
            if ($game) {
                $lesson->update(['strategy_game_id' => $game->id]);
                $lesson->setRelation('strategyGame', $game);
            }
        }

        return view('lesson.player', compact('lesson'));
    }
}
