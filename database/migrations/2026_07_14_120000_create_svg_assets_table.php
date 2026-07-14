<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teacher-owned library of public-domain / freely-licensed SVGs imported from
 * the web (Wikimedia Commons, freesvg.org, …). Sanitised on import and stored
 * on the public disk; the ink drawing engine samples their paths client-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svg_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('source');            // commons | freesvg | url
            $table->string('source_ref');        // file title / slug — dedupe key per owner
            $table->string('source_url');        // canonical page for attribution
            $table->string('title');
            $table->string('license');           // "Public domain", "CC BY-SA 4.0", …
            $table->string('attribution')->nullable();

            $table->string('svg_path');          // sanitised file on the public disk
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('view_box')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'source', 'source_ref']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svg_assets');
    }
};
