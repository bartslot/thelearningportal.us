<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            // Per-language preferred narration voice: {"nl": "nl-NL-FennaNeural", "en": "..."}.
            // voice_id stays as the fallback when a lesson's language has no entry.
            $table->json('voice_map')->nullable()->after('voice_id');
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropColumn('voice_map');
        });
    }
};
