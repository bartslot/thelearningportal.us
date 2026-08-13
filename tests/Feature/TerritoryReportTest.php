<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TerritoryReport;
use App\Models\User;
use App\Services\HandTerritories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Something not right? Report" — the teacher feedback loop on the Time-Map's borders.
 *
 * The tests worth having here are not "does it return 201". They are about whether the report that
 * lands is ACTIONABLE, because a report you cannot act on is indistinguishable from no report at
 * all until someone tries to use it:
 *
 *   - the year has to survive, or the reported border cannot be put back on screen;
 *   - the provenance has to come from OUR file, not from the request, or a report can assert its
 *     own context and stops being evidence of anything;
 *   - a report that names no territory has to be refused, or it is a complaint into the void.
 */
class TerritoryReportTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        return User::factory()->create(['role' => 'teacher']);
    }

    public function test_a_teacher_can_report_a_hand_authored_territory(): void
    {
        $response = $this->actingAs($this->teacher())->postJson('/teacher/timemap/report', [
            'territory_id' => 'cherusci',
            'wikidata_qid' => 'Q268307',
            'label' => 'Cherusci',
            'source' => 'hand',
            'year' => 9,
            'comment' => 'This should reach the Elbe, Tacitus says so.',
        ]);

        $response->assertCreated()->assertJson(['ok' => true]);

        $report = TerritoryReport::sole();
        $this->assertSame('cherusci', $report->territory_id);
        $this->assertSame(9, $report->year);
        $this->assertTrue($report->isHandAuthored());
    }

    public function test_the_report_records_what_we_claimed_rather_than_what_the_request_said(): void
    {
        // THE test. A browser that can name its own confidence can make every report say the border
        // was certain, and the first thing anyone asks of a report is "which source, and how sure
        // were we?" — so those two fields have to come from the committed file or they are worthless.
        $claimed = app(HandTerritories::class)->claimFor('suebi-elbe');
        $this->assertSame('low', $claimed['confidence'], 'fixture drifted: the Suebi should be low confidence');

        $this->actingAs($this->teacher())->postJson('/teacher/timemap/report', [
            'territory_id' => 'suebi-elbe',
            'label' => 'Suebi',
            'source' => 'hand',
            'year' => 9,
            'comment' => 'Caesar met these on the Rhine, not the Elbe.',
            // A lie, and one the endpoint must not repeat back.
            'confidence' => 'high',
            'chosen_source' => 'made-up-source',
        ]);

        $report = TerritoryReport::sole();
        $this->assertSame('low', $report->confidence);
        $this->assertSame('barrington-awmc', $report->chosen_source);
        // The QID was never sent; it is filled in from the same record.
        $this->assertSame('Q155085', $report->wikidata_qid);
    }

    public function test_a_cliopatria_territory_can_be_reported_too(): void
    {
        // Not a hand-authored id, so there is no provenance of ours to stamp — and that must be a
        // null rather than a refusal. An imported border being wrong is every bit as worth hearing.
        $this->actingAs($this->teacher())->postJson('/teacher/timemap/report', [
            'wikidata_qid' => 'Q17167',
            'label' => 'Roman Republic',
            'source' => 'cliopatria',
            'year' => -33,
            'comment' => 'The panel shows the 1798 republic.',
        ])->assertCreated();

        $report = TerritoryReport::sole();
        $this->assertNull($report->territory_id);
        $this->assertNull($report->confidence);
        $this->assertSame(-33, $report->year);
        $this->assertFalse($report->isHandAuthored());
    }

    public function test_a_report_that_names_no_territory_is_refused(): void
    {
        $this->actingAs($this->teacher())->postJson('/teacher/timemap/report', [
            'label' => 'Something',
            'source' => 'hand',
            'year' => 9,
            'comment' => 'It is wrong.',
        ])->assertJsonValidationErrors('territory_id');

        $this->assertSame(0, TerritoryReport::count());
    }

    public function test_an_empty_comment_is_refused(): void
    {
        $this->actingAs($this->teacher())->postJson('/teacher/timemap/report', [
            'territory_id' => 'cherusci',
            'label' => 'Cherusci',
            'source' => 'hand',
            'year' => 9,
            'comment' => '  ',
        ])->assertJsonValidationErrors('comment');
    }

    public function test_a_year_outside_the_sliders_range_is_refused(): void
    {
        // A year that could not have been on screen did not come from the slider, and a report whose
        // year is wrong is worse than one with no year: it sends the next person to the wrong map.
        $this->actingAs($this->teacher())->postJson('/teacher/timemap/report', [
            'territory_id' => 'cherusci',
            'label' => 'Cherusci',
            'source' => 'hand',
            'year' => 999999,
            'comment' => 'Wrong border.',
        ])->assertJsonValidationErrors('year');
    }

    public function test_reporting_requires_a_signed_in_teacher(): void
    {
        $this->postJson('/teacher/timemap/report', [
            'territory_id' => 'cherusci',
            'label' => 'Cherusci',
            'source' => 'hand',
            'year' => 9,
            'comment' => 'Wrong border.',
        ])->assertUnauthorized();

        $this->assertSame(0, TerritoryReport::count());
    }
}
