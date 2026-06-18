<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('type');                                  // App\Enums\ModuleType value
            $table->string('title')->nullable();
            $table->unsignedInteger('order')->default(0);
            // Mirror scenes.config exactly: plain JSON, nullable (casts to array on the model).
            $table->json('config')->nullable();
            $table->string('content_ref')->nullable();               // optional pointer to a heavy row
            $table->unsignedInteger('estimated_duration_seconds')->default(0);
            $table->string('status')->default('ready');              // ready|needs_review|missing_content|generation_failed
            $table->timestamps();

            $table->unique(['lesson_id', 'order']);
            $table->index(['lesson_id', 'order']);
        });

        $this->backfillFromScenes();
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_modules');
    }

    /**
     * D2 transition: copy existing `scenes` rows into `lesson_modules` so nothing is lost.
     * `scenes` is left intact and is removed later (K-1b) once the player reads modules.
     * No-op on a fresh database (e.g. RefreshDatabase in tests).
     */
    private function backfillFromScenes(): void
    {
        if (! Schema::hasTable('scenes')) {
            return;
        }

        $hasStrategyGameId = Schema::hasColumn('scenes', 'strategy_game_id');

        // scenes.kind has no enum constraint anymore (see drop_stale_enum_check_constraints).
        $knownKinds = ['narration', 'game', 'strategy', 'map', 'quiz'];

        $typeFor = static fn (?string $kind): string => match ($kind) {
            'narration' => 'story_block',
            'game', 'strategy' => 'strategy_game',
            'map' => 'timeline_map',
            'quiz' => 'quiz_mcq',
            default => 'story_block',
        };

        $statusFor = static fn (?string $status): string => match ($status) {
            'ready' => 'ready',
            'failed' => 'generation_failed',
            default => 'needs_review',
        };

        DB::table('scenes')->orderBy('id')->chunk(200, function ($scenes) use ($typeFor, $statusFor, $knownKinds, $hasStrategyGameId): void {
            $rows = [];

            foreach ($scenes as $scene) {
                $kindKnown = in_array($scene->kind, $knownKinds, true);

                $rows[] = [
                    'lesson_id' => $scene->lesson_id,
                    'type' => $typeFor($scene->kind),
                    'title' => null,
                    'order' => $scene->order,
                    'config' => $scene->config,                       // raw JSON string or null
                    'content_ref' => ($hasStrategyGameId && $scene->strategy_game_id)
                        ? (string) $scene->strategy_game_id
                        : null,
                    'estimated_duration_seconds' => $scene->duration_seconds ?? 0,
                    // Unknown kinds always need a human look, regardless of scene status.
                    'status' => $kindKnown ? $statusFor($scene->status) : 'needs_review',
                    'created_at' => $scene->created_at,
                    'updated_at' => $scene->updated_at,
                ];
            }

            if ($rows !== []) {
                DB::table('lesson_modules')->insert($rows);
            }
        });
    }
};
