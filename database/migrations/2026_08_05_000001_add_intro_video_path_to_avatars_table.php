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
        Schema::table('avatars', function (Blueprint $table): void {
            $table->string('intro_video_path')->nullable()->after('portrait_path');
        });

        // Julian Redthorne's introduction predates this field. Connect the existing
        // public asset so it follows the same playback path as newly uploaded videos.
        DB::table('avatars')
            ->where('id', 31)
            ->whereNull('intro_video_path')
            ->update(['intro_video_path' => 'avatars/31/welcome.mp4']);
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropColumn('intro_video_path');
        });
    }
};
