<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Something not right? Report" — a teacher pushing back on a border.
 *
 * A teacher who spots a mistake is the cheapest reviewer this dataset will ever get, and the whole
 * value of the report is in the CONTEXT it arrives with. "The Cherusci border is wrong" is not
 * actionable; "the Cherusci at 9 CE, hand-authored, drawn from the Barrington Atlas, and here is
 * what the teacher said" is.
 *
 * So the columns are chosen to answer the first question a report always raises — which source, and
 * what did the others say? — without anyone having to go back and ask.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territory_reports', function (Blueprint $table): void {
            $table->id();

            // Nullable: the map is behind auth today, but a report is worth keeping even if the
            // route is ever opened to a guest demo, and a deleted account must not delete evidence.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // WHAT was reported. `territory_id` is our own slug for a hand-authored shape and is
            // null for a Cliopatria one; `wikidata_qid` is the other way round more often than not.
            // Both are kept because either alone loses a whole class of report.
            $table->string('territory_id')->nullable();
            $table->string('wikidata_qid')->nullable();
            $table->string('label');
            $table->string('source', 32); // 'hand' | 'cliopatria'

            // WHEN on the slider. Without this a report about a border that only exists for two
            // centuries cannot be reproduced — you cannot see what they saw.
            $table->integer('year');

            // WHAT WE CLAIMED at the time, so a later fix can tell whether the reported shape is
            // still the shape being complained about.
            $table->string('confidence', 16)->nullable();
            $table->string('chosen_source')->nullable();

            $table->text('comment');
            $table->timestamps();

            // The only query this table has: what has come in, newest first.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_reports');
    }
};
