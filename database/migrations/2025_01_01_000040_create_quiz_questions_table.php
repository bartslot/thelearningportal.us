<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('order')->default(0);
            $table->text('question');
            $table->json('options');          // ["Caesar crossed the Rubicon", "Caesar fled Rome", ...]
            $table->unsignedTinyInteger('correct_index'); // 0-based index into options array
            $table->text('explanation')->nullable(); // why this answer is correct
            $table->unsignedSmallInteger('points')->default(10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
