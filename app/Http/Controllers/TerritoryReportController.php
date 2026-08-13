<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTerritoryReportRequest;
use App\Models\TerritoryReport;
use App\Services\HandTerritories;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * "Something not right? Report" on the Time-Map's territory card.
 *
 * Historical borders are uncertain and teachers are the cheapest reviewers this dataset will ever
 * get. The job here is to make their report ACTIONABLE, which means arriving with the context that
 * answers the first question every report raises: which source was this drawn from, and what did
 * the others say?
 */
class TerritoryReportController extends Controller
{
    public function store(StoreTerritoryReportRequest $request, HandTerritories $territories): JsonResponse
    {
        $validated = $request->validated();
        $territoryId = $validated['territory_id'] ?? null;

        // What WE claim, read from the committed file rather than taken from the request. A report
        // whose "confidence: high" came from the browser is a report that proves nothing about what
        // the teacher was actually shown.
        $claim = $territoryId !== null ? $territories->claimFor($territoryId) : [];

        $report = TerritoryReport::create([
            'user_id' => $request->user()?->id,
            'territory_id' => $territoryId,
            'wikidata_qid' => $validated['wikidata_qid'] ?? $claim['qid'] ?? null,
            // The label is the teacher's own view of it, which is worth keeping even where it
            // differs from ours — a name they did not recognise is itself a finding.
            'label' => $validated['label'],
            'source' => $validated['source'],
            'year' => $validated['year'],
            'confidence' => $claim['confidence'] ?? null,
            'chosen_source' => $claim['chosen_source'] ?? null,
            'comment' => $validated['comment'],
        ]);

        // Also to the log, because a row in a table nobody queries is a report nobody reads. The
        // artisan command (timemap:territory-reports) is the intended way in; this is the trail.
        Log::info('Time-Map territory reported', [
            'id' => $report->id,
            'territory' => $report->territory_id ?? $report->wikidata_qid,
            'label' => $report->label,
            'year' => $report->year,
            'source' => $report->source,
        ]);

        return response()->json(['ok' => true, 'id' => $report->id], 201);
    }
}
