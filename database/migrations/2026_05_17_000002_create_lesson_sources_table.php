<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->enum('kind', ['pdf', 'docx', 'wikipedia', 'both']);
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->longText('extracted_text');
            $table->string('wikipedia_topic')->nullable();
            $table->timestamps();

            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sources');
    }
};
