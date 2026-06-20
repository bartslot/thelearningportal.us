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
            // The chosen story arc; existing lessons fall back to the Hero's journey default.
            $table->string('narrative_framework')->default('heros_journey')->after('game_type');
            // Corpus figure chosen as the protagonist (null when typed free-text).
            $table->string('protagonist_qid')->nullable()->after('narrative_framework');
            $table->string('protagonist_name')->nullable()->after('protagonist_qid');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn(['narrative_framework', 'protagonist_qid', 'protagonist_name']);
        });
    }
};
