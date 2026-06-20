<?php

declare(strict_types=1);

namespace Tests\Feature\Corpus;

use App\Models\Corpus\Figure;
use Tests\TestCase;

/**
 * Scope logic is asserted against the generated SQL so these tests need no live corpus connection.
 */
class FigureScopesTest extends TestCase
{
    public function test_uses_the_read_only_corpus_connection(): void
    {
        $this->assertSame('pgsql_corpus', (new Figure)->getConnectionName());
    }

    public function test_for_topic_filters_by_parent_and_ranks_rulers_first(): void
    {
        $query = Figure::query()->forTopic('Q12560');
        $sql = strtolower($query->toSql());

        $this->assertStringContainsString('"parent_qid" = ?', $query->toSql());
        $this->assertStringContainsString("case when figure_kind = 'ruler'", $sql);
        $this->assertStringContainsString('order by', $sql);
        $this->assertContains('Q12560', $query->getBindings());
    }

    public function test_overlapping_era_intersects_lifetimes(): void
    {
        $sql = strtolower(Figure::query()->overlappingEra(1300, 1923)->toSql());

        $this->assertStringContainsString('era_start', $sql);
        $this->assertStringContainsString('era_end', $sql);
    }

    public function test_overlapping_era_is_a_noop_when_both_bounds_are_null(): void
    {
        $sql = strtolower(Figure::query()->overlappingEra(null, null)->toSql());

        $this->assertStringNotContainsString('era_start', $sql);
    }
}
