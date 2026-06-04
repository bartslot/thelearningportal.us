<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->string('source_url')->nullable()->after('wikipedia_topic');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
