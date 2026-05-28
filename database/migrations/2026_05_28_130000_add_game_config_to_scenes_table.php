<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->string('game_type', 20)->nullable()->after('kind');
            $table->unsignedTinyInteger('quiz_question_count')->nullable()->after('game_type');
            $table->string('quiz_timing', 10)->nullable()->after('quiz_question_count');
            $table->foreignId('strategy_game_id')
                ->nullable()
                ->after('quiz_timing')
                ->constrained('strategy_games')
                ->nullOnDelete();
            $table->unsignedTinyInteger('team_count')->nullable()->after('strategy_game_id');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropForeign(['strategy_game_id']);
            $table->dropColumn([
                'game_type',
                'quiz_question_count',
                'quiz_timing',
                'strategy_game_id',
                'team_count',
            ]);
        });
    }
};
