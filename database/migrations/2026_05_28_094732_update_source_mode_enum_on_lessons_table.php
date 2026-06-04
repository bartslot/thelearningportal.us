<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite does not support ALTER COLUMN for enums, so we:
     *   1. Add a temporary column
     *   2. Copy + remap old values (wikipedia→internet, upload→local)
     *   3. Drop old column, rename temp to source_mode
     *
     * Old enum: 'wikipedia' | 'upload' | 'both'
     * New values: 'internet' | 'local' | 'both'
     */
    public function up(): void
    {
        // 1. Add temp column (no constraint yet)
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('source_mode_new')->nullable()->after('source_mode');
        });

        // 2. Copy & remap
        DB::statement("
            UPDATE lessons SET source_mode_new = CASE source_mode
                WHEN 'wikipedia' THEN 'internet'
                WHEN 'upload'    THEN 'local'
                ELSE source_mode
            END
        ");

        // 3. Drop old, rename new
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('source_mode');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->renameColumn('source_mode_new', 'source_mode');
        });

        // 4. Set default (SQLite: CHECK constraints are added via the original
        //    migration; removing the old CHECK requires table recreation which
        //    Laravel handles via ->change() in some drivers). For SQLite the
        //    prior CHECK is gone after the column recreate above.
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('source_mode')->default('internet')->change();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('source_mode_rev')->nullable()->after('source_mode');
        });

        DB::statement("
            UPDATE lessons SET source_mode_rev = CASE source_mode
                WHEN 'internet' THEN 'wikipedia'
                WHEN 'local'    THEN 'upload'
                ELSE source_mode
            END
        ");

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('source_mode');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->renameColumn('source_mode_rev', 'source_mode');
        });
    }
};
