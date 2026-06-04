<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * lesson_sources.kind: replace 'wikipedia' with 'internet' in the allowed values.
     *
     * Old enum: ['pdf', 'docx', 'wikipedia', 'both']
     * New enum: ['pdf', 'docx', 'internet', 'both']
     *
     * SQLite does not support ALTER COLUMN, so we use add → copy → drop → rename.
     */
    public function up(): void
    {
        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->string('kind_new')->nullable()->after('kind');
        });

        DB::statement("
            UPDATE lesson_sources SET kind_new = CASE kind
                WHEN 'wikipedia' THEN 'internet'
                ELSE kind
            END
        ");

        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->renameColumn('kind_new', 'kind');
        });

        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->string('kind')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->string('kind_rev')->nullable()->after('kind');
        });

        DB::statement("
            UPDATE lesson_sources SET kind_rev = CASE kind
                WHEN 'internet' THEN 'wikipedia'
                ELSE kind
            END
        ");

        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->renameColumn('kind_rev', 'kind');
        });
    }
};
