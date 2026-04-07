<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            // How the avatar introduces itself: "I am [short_name]"
            $table->string('short_name')->nullable()->after('name');

            // The role used in the greeting: "a [avatar_title] here at The History Portal"
            $table->string('avatar_title')->nullable()->after('short_name');
        });

        Schema::create('avatar_teacher_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('avatar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();

            // null = no response yet, true = 👍, false = 👎
            $table->boolean('liked')->nullable();

            // Cached personalised greeting audio for this teacher
            $table->string('greeting_audio_path')->nullable();

            $table->unique(['avatar_id', 'teacher_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_teacher_feedback');
        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropColumn(['short_name', 'avatar_title']);
        });
    }
};
