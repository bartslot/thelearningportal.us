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
            // [{order, description, composition, anchor_sentence, image_path}] — the
            // per-scene storyboard. Null = legacy single-image scene (player falls back).
            $table->json('shots')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn('shots');
        });
    }
};
