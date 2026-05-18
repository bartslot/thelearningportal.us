<?php

declare(strict_types=1);

use App\Support\SlideshowAndIntelDropBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        (new SlideshowAndIntelDropBackfill())->backfill();
    }

    public function down(): void
    {
        // Irreversible — Scene rows would need to be regenerated from source. No-op.
    }
};
