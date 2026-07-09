<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            // Game-story (spel-verhaal) lesson config: meters, roles, print_pack flag.
            // Spec: docs/superpowers/specs/2026-07-09-game-story-lesson-design.md §4.1
            $table->json('game_config')->nullable()->after('game_type');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('game_config');
        });
    }
};
