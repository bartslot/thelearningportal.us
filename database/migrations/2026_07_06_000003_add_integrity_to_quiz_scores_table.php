<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_scores', function (Blueprint $table): void {
            // Engagement/integrity telemetry from the player: avg response ms, rapid-guess
            // count, same-letter streak, tab/focus drops. Teacher-eyes only — students and
            // the public leaderboard never see it.
            $table->json('integrity')->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_scores', function (Blueprint $table): void {
            $table->dropColumn('integrity');
        });
    }
};
