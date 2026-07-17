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
            $table->string('canon_theme', 48)->nullable()->after('subject')->index();
            $table->string('canon_intent', 20)->nullable()->after('canon_theme');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex(['canon_theme']);
            $table->dropColumn(['canon_theme', 'canon_intent']);
        });
    }
};
