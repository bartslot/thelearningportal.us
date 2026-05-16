<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->enum('image_style', [
                'realistic', 'sketched', 'painted', 'cinematic', 'comic', 'animation',
            ])->default('realistic')->after('details');

            $table->enum('source_mode', ['wikipedia', 'upload', 'both'])
                ->default('wikipedia')
                ->after('image_style');

            $table->unsignedTinyInteger('team_count')->nullable()->after('strategy_game_id');
            $table->unsignedTinyInteger('game_split_count')->default(1)->after('team_count');

            $table->json('outline')->nullable()->after('script');
            $table->unsignedTinyInteger('wizard_step')->default(1)->after('outline');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn([
                'image_style', 'source_mode', 'team_count',
                'game_split_count', 'outline', 'wizard_step',
            ]);
        });
    }
};
