<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `locale` drives per-user UI language (e.g. 'nl' for Dutch pilot teachers); `tag` is a
     * free-form marker to categorise accounts (e.g. 'pilot-teacher', 'test', 'customer').
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->default('en')->after('role');
            $table->string('tag')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'tag']);
        });
    }
};
