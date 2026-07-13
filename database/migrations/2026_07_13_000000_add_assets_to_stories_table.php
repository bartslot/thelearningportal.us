<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            // One-time generated visual pack (E3b-A), human-reviewed in StoryReview:
            // {
            //   "backgrounds": [{"tag": string, "lighting": "day"|"dusk"|"night", "image_path": string}],
            //   "hero":        [{"pose": string, "image_path": string}],
            //   "generated_at": iso8601, "style": "ink"
            // }
            // Files live under storage/app/public/stories/{id}/assets/…
            $table->json('assets')->nullable()->after('quiz_blueprint');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn('assets');
        });
    }
};
