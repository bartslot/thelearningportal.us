<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The icon library that ships WITH the app, alongside the teacher's own imports.
 *
 * A bundled icon has no owner (user_id null) and is filed under collection ▸ category ▸
 * subcategory — the three rows of pills in the Icons panel. A teacher-imported SVG keeps
 * its user_id and leaves the three filing columns empty, so both live in one table and the
 * whole layer pipeline (shots[].layers keyed on asset_id) stays as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('svg_assets', function (Blueprint $table) {
            // Bundled icons belong to nobody: they are available to every teacher.
            $table->foreignId('user_id')->nullable()->change();

            $table->string('collection')->nullable()->after('source_ref');   // line-art | arrows | shapes
            $table->string('category')->nullable()->after('collection');     // Civilisations, Prehistoric, …
            $table->string('subcategory')->nullable()->after('category');    // Egyptians, Dinosaurs, …

            $table->index(['collection', 'category', 'subcategory'], 'svg_assets_library_idx');
        });
    }

    public function down(): void
    {
        Schema::table('svg_assets', function (Blueprint $table) {
            $table->dropIndex('svg_assets_library_idx');
            $table->dropColumn(['collection', 'category', 'subcategory']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
