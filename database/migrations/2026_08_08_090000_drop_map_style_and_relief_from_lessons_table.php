<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The map has one look now, so there is nothing left for these two columns to hold.
 *
 * `map_style` chose between five palettes — Soft Atlas, Antique, Tolkien, Night and Satellite —
 * and `map_relief` was an opt-in 3D terrain slider that defaulted to 0. Between them they let a
 * lesson about Hannibal crossing the Alps render as a flat sheet of parchment, which is what The
 * Punic Wars was actually doing on production: style `antique`, relief 0. Satellite imagery with
 * the ground raised and the camera tilted is now simply what a map is (resources/js/lesson-map.js),
 * so neither the teacher nor the spec picks it any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn(['map_style', 'map_relief']);
        });

        // A map block could also override the lesson's palette for itself, in scene config. Nothing
        // reads that key any more, but left behind it is worse than inert: LessonExporter treats
        // any config key its composer does not write as the teacher's own work and carries it into
        // the exported spec, so a dead setting would be copied forward into every rebuild.
        foreach (DB::table('scenes')->select('id', 'config')->where('kind', 'map')->cursor() as $scene) {
            $config = json_decode((string) $scene->config, true);
            if (! is_array($config) || ! array_key_exists('map_style', $config)) {
                continue;
            }

            unset($config['map_style']);
            DB::table('scenes')->where('id', $scene->id)->update(['config' => json_encode($config)]);
        }
    }

    /**
     * Restores the columns, not the feature. Rolling back gives every lesson the defaults it had
     * before anyone chose anything — which for `map_style` is null, i.e. the old app default.
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('map_style', 20)->nullable()->after('quiz_timing');
            $table->float('map_relief')->default(0)->after('map_style');
        });
    }
};
