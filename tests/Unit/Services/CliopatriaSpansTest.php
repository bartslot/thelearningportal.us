<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CliopatriaSpans;
use Tests\TestCase;

class CliopatriaSpansTest extends TestCase
{
    public function test_it_returns_the_committed_span_for_a_known_qid(): void
    {
        // Q172579 "Kingdom of Italy" is the lineage-conflation case: Cliopatria draws it from 587 CE
        // (Lombard/medieval) through 1946, even though Wikidata's inception is the 1861 modern kingdom.
        $span = (new CliopatriaSpans)->for('Q172579');

        $this->assertSame(['from' => 587, 'to' => 1946], $span);
    }

    public function test_it_returns_null_for_a_polity_not_in_the_list(): void
    {
        $this->assertNull((new CliopatriaSpans)->for('Q0'));
    }

    public function test_all_is_keyed_by_qid_with_integer_bounds(): void
    {
        $all = (new CliopatriaSpans)->all();

        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('Q172579', $all);
        $this->assertIsInt($all['Q172579']['from']);
        $this->assertIsInt($all['Q172579']['to']);
    }
}
