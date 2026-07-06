<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();

            // Curated-catalog link ("polity:Q…") — reuses the map block + corpus figures.
            $table->string('topic_id')->nullable();
            $table->string('protagonist_qid')->nullable();
            $table->string('protagonist_name')->nullable();

            // String, not enum(): Postgres enum CHECK constraints have bitten us before
            // (see 2026_06_04 drop_stale_enum_check_constraints) — validation lives in PHP enums.
            $table->string('narrative_framework')->default('heros_journey');

            $table->integer('era_start')->nullable();
            $table->integer('era_end')->nullable();
            $table->string('region')->nullable();
            $table->string('grade_band')->nullable();     // 'K-2' | '3-5' | '6-8' | '9-12'
            $table->string('locale', 8)->default('en');   // primary teaching locale ('nl' for Canon)

            $table->json('learning_objectives')->nullable(); // [{id,text,bloom}]
            $table->json('key_facts')->nullable();           // [{fact,source_ref}]
            $table->json('misconceptions')->nullable();      // [{misconception,correction}]
            $table->json('quiz_blueprint')->nullable();      // [{objective_id,style,count}]

            $table->string('status')->default('draft');      // StoryStatus: draft|reviewed|published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
