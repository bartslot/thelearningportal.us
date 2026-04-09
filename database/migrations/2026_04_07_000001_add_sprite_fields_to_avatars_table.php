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
            $table->string('portrait_original_path')->nullable()->after('portrait_path');
            $table->json('landmarks_json')->nullable()->after('portrait_original_path');
            $table->string('sprite_status')->default('pending')->after('landmarks_json');
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropColumn(['portrait_original_path', 'landmarks_json', 'sprite_status']);
        });
    }
};
