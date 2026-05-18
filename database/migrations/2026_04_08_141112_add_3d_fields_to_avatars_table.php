<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('avatars', function (Blueprint $table) {
            $table->enum('presentation_mode', ['fullscreen', 'framed', 'hidden'])
                  ->default('framed')->after('portrait_path');
            $table->tinyInteger('age')->unsigned()
                  ->default(35)->after('presentation_mode');
            $table->enum('gender', ['male', 'female'])
                  ->default('male')->after('age');
            $table->enum('emotion_style', ['auto', 'narrative', 'cheerful', 'serious', 'excited', 'empathetic', 'whispering'])
                  ->default('auto')->after('gender');
            $table->decimal('expressiveness', 3, 2)
                  ->default(1.20)->after('emotion_style');
            $table->decimal('speaking_speed', 3, 2)
                  ->default(1.00)->after('expressiveness');
            $table->string('frame_background', 7)
                  ->default('#0f172a')->after('speaking_speed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table) {
            $table->dropColumn([
                'presentation_mode', 'age', 'gender', 'emotion_style',
                'expressiveness', 'speaking_speed', 'frame_background',
            ]);
        });
    }
};
