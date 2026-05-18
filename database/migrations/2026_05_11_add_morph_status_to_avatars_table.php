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
            // 'pending' = not yet uploaded, 'processing' = Blender running,
            // 'ready' = morphs transferred, 'failed' = Blender error
            $table->string('morph_status')->default('ready')->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropColumn('morph_status');
        });
    }
};
