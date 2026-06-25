<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cities data layer for the Time-Map / lesson-map features. Seeded from Natural Earth
 * populated places (app:import-cities) and enriched with curated historical names + a
 * polity → capital mapping. Server/data only — no UI depends on this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                                  // modern name
            $table->double('lat');
            $table->double('lng');
            $table->integer('scalerank')->default(99);               // Natural Earth importance (lower = bigger)
            $table->bigInteger('pop')->nullable();                   // POP_MAX from Natural Earth
            $table->boolean('is_capital')->default(false);
            $table->string('country')->nullable();                   // sovereign / admin-0 name
            $table->string('historical_name')->nullable();           // e.g. "Constantinople"
            $table->string('historical_period')->nullable();         // e.g. "330–1453 CE"
            $table->string('wikidata_qid')->nullable();              // e.g. "Q406"
            $table->timestamps();

            $table->index('name');
            $table->index(['country', 'is_capital']);
            // Conflict target for app:import-cities upserts (Postgres ON CONFLICT needs a
            // unique constraint). `country` is nullable, so two cities with the same name and
            // a NULL country are treated as distinct under standard SQL NULL semantics.
            $table->unique(['name', 'country']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
