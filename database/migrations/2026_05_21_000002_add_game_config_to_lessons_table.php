<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('game_type', 20)->nullable()->after('include_game');
            $table->unsignedTinyInteger('quiz_question_count')->nullable()->after('game_type');
            $table->string('quiz_timing', 10)->nullable()->after('quiz_question_count');
            $table->string('strategy_game', 100)->nullable()->after('quiz_timing');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['game_type', 'quiz_question_count', 'quiz_timing', 'strategy_game']);
        });
    }
};
