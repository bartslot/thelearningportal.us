<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('avatars', function (Blueprint $table) {
            $table->string('skin_tone', 10)->nullable()->after('gender');
            $table->string('body_type', 30)->nullable()->after('skin_tone');
            $table->string('source_id', 60)->nullable()->after('body_type');
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table) {
            $table->dropColumn(['skin_tone', 'body_type', 'source_id']);
        });
    }
};
