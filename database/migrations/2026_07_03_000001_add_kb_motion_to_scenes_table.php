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
            // Per-scene background motion: toggle + Ken Burns direction
            // (null = auto-rotate through the classic corner pans).
            $table->boolean('kb_animated')->default(true)->after('background_color');
            $table->string('kb_direction')->nullable()->after('kb_animated');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn(['kb_animated', 'kb_direction']);
        });
    }
};
