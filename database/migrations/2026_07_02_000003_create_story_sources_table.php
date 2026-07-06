<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->string('origin');                 // gutenberg|dbnl|corpus|canon|worldhistory|manual
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('license')->nullable();    // e.g. "Public domain", "CC BY-SA 4.0"
            $table->string('url')->nullable();
            $table->longText('excerpt');              // curated NARRATIVE excerpt that grounds generation
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_sources');
    }
};
