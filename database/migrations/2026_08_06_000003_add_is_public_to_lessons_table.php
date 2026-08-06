<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a lesson is shared with every teacher, or only its author's.
 *
 * Until now a lesson belonged to exactly one teacher and nobody else could see it, so the library a
 * new teacher signed in to was empty. Public lessons are the shared shelf: visible to everyone,
 * still owned and edited by whoever wrote them, and copied into your own library when you want to
 * change one for your class.
 *
 * Defaults to false. Sharing is a decision somebody makes, never something that happens to a lesson
 * because a column was added underneath it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('status');
            // Every teacher's library reads "mine OR public", so this index is on the hot path.
            $table->index(['is_public', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['is_public', 'status']);
            $table->dropColumn('is_public');
        });
    }
};
