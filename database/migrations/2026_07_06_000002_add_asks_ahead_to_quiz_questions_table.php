<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            // True when the question tests narration that comes AFTER its quiz segment —
            // deliberate "what do you already know?" pre-assessment. The editor warns the
            // teacher; the player softens wrong-answer feedback ("you'll hear this later").
            $table->boolean('asks_ahead')->default(false)->after('correct_index');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->dropColumn('asks_ahead');
        });
    }
};
