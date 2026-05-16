<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_games', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('subject')->default('History');
            $table->json('topic_keywords');
            $table->unsignedTinyInteger('grade_min')->default(6);
            $table->unsignedTinyInteger('grade_max')->default(12);
            $table->unsignedTinyInteger('team_size_min')->default(3);
            $table->unsignedTinyInteger('team_size_max')->default(5);
            $table->unsignedTinyInteger('duration_minutes')->default(10);
            $table->text('instructions');
            $table->json('materials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Add game FK to lessons
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('strategy_game_id')
                ->nullable()
                ->after('avatar_id')
                ->constrained('strategy_games')
                ->nullOnDelete();

            // Intel drop fields
            $table->boolean('intel_drop_enabled')->default(false)->after('slideshow_images');
            $table->unsignedTinyInteger('intel_drop_at_minutes')->default(5)->after('intel_drop_enabled');
            $table->text('intel_drop_script')->nullable()->after('intel_drop_at_minutes');
            $table->string('intel_drop_audio_path')->nullable()->after('intel_drop_script');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['strategy_game_id']);
            $table->dropColumn(['strategy_game_id', 'intel_drop_enabled', 'intel_drop_at_minutes', 'intel_drop_script', 'intel_drop_audio_path']);
        });

        Schema::dropIfExists('strategy_games');
    }
};
