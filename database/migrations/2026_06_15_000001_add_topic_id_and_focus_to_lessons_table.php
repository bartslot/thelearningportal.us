<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // Curated-catalog reference, e.g. "polity:Q2277" (A1). Null for legacy free-text lessons.
            $table->string('topic_id')->nullable()->after('topic');
            // Optional free-text angle/focus, e.g. "daily life of a soldier".
            $table->text('focus')->nullable()->after('topic_id');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['topic_id', 'focus']);
        });
    }
};
