<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LessonStatus;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\StrategyGame;
use Illuminate\Database\Seeder;

class FrenchRevolutionDemoSeeder extends Seeder
{
    public function run(): void
    {
        $game = StrategyGame::where('slug', 'napoleon-waterloo-1815')->first();

        // Find or create a demo teacher user
        $teacher = \App\Models\User::firstOrCreate(
            ['email' => 'demo@thelearningportal.us'],
            [
                'name'     => 'Demo Teacher',
                'password' => bcrypt('demo1234'),
                'role'     => 'teacher',
            ]
        );

        $avatar = Avatar::active()->first();

        Lesson::updateOrCreate(
            ['lesson_code' => 'FRREV9'],
            [
                'teacher_id'         => $teacher->id,
                'avatar_id'          => $avatar?->id,
                'strategy_game_id'   => $game?->id,
                'title'              => 'The French Revolution & Napoleon\'s Rise',
                'topic'              => 'French Revolution',
                'subject'            => 'History',
                'grade_level'        => 9,
                'tone'               => 'dramatic',
                'historical_figure'  => 'Napoleon Bonaparte',
                'status'             => LessonStatus::Published,
                'intel_drop_enabled' => true,
                'intel_drop_at_minutes' => 5,
                'intel_drop_script'  => "Urgent news from the battlefield! A Prussian force under General Blücher has been spotted just 6 miles east — they will arrive within the hour. [position:middle] Your strategy must account for fighting on two fronts simultaneously. Adapt or face total defeat.",
                'script'             => <<<'SCRIPT'
[position:bottom-right] It is 1789. France is burning.

The people are starving. The king lives in a palace of gold while children beg for bread. For centuries, the common people of France — the peasants, the tradesmen, the thinkers — have had no voice. No power. No future.

[position:left-centre] That is about to change.

On the 14th of July, 1789, a mob storms the Bastille prison — a symbol of royal tyranny. The French Revolution has begun. In the chaos that follows, the old world is torn apart. Kings tremble. The nobility flees. And from the ashes, something extraordinary emerges.

[position:right-centre] New ideas sweep across Europe. Liberty. Equality. Fraternity. But revolution is never clean. The guillotine falls. Thousands die. Leaders rise and fall within months. France is at war with all of Europe.

[position:bottom-right] And then — a young general from Corsica steps forward.

Napoleon Bonaparte. A military genius who could see a battlefield like a chess board. In campaign after campaign, he defeats the armies of Austria, Prussia, and Russia. He crowns himself Emperor. For a brief, brilliant moment, one man reshapes the entire continent.

[teams] But every empire has its end.

[position:middle] By 1815, Napoleon has been defeated, exiled, and has escaped exile once more. He raises a new army. He marches north. At a small village in Belgium called Waterloo, everything comes down to one final battle.

This is where your story begins.

[game] You are Napoleon's war council. In the next ten minutes, you must design a strategy that could change history.
SCRIPT,
            ]
        );
    }
}
