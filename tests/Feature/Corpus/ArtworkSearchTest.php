<?php

declare(strict_types=1);

namespace Tests\Feature\Corpus;

use App\Models\Corpus\Artwork;
use Tests\TestCase;

/**
 * The picker's search, asserted against the generated SQL so no live corpus connection is needed.
 *
 * Every corpus row carries ONE title, in whichever language the harvest happened to store — 8.5k
 * Dutch, 4.2k English. A Dutch teacher searching "nachtwacht" therefore missed the English-titled
 * row, and an English search missed the Dutch one, while the empty state cheerfully suggested
 * trying "a name, place or event, in Dutch or English".
 */
class ArtworkSearchTest extends TestCase
{
    public function test_it_reads_the_corpus_connection(): void
    {
        $this->assertSame('pgsql_corpus', (new Artwork)->getConnectionName());
    }

    public function test_search_matches_the_alternate_language_titles(): void
    {
        $sql = Artwork::search('nachtwacht')->toSql();

        $this->assertStringContainsString("extra->>'title_nl' ILIKE ?", $sql);
        $this->assertStringContainsString("extra->>'title_en' ILIKE ?", $sql);
    }

    public function test_search_still_matches_the_title_the_creator_and_what_is_depicted(): void
    {
        $query = Artwork::search('rembrandt');
        $sql = $query->toSql();

        // Postgres casts before ILIKE, so the generated SQL reads "title"::text ILIKE ?
        $this->assertStringContainsString('"title"::text ILIKE ?', $sql);
        $this->assertStringContainsString('"creator_name"::text ILIKE ?', $sql);
        $this->assertStringContainsString('artwork_links', $sql, 'depicted-subject search must survive');
    }

    public function test_every_language_branch_gets_the_same_bound_term(): void
    {
        $bindings = Artwork::search('de nachtwacht')->getBindings();
        $like = array_values(array_filter($bindings, fn ($b) => is_string($b) && str_contains($b, 'nachtwacht')));

        // title, creator_name, title_nl, title_en, artwork_links.target_label
        $this->assertCount(5, $like);
        foreach ($like as $b) {
            $this->assertSame('%de nachtwacht%', $b);
        }
    }

    public function test_search_hides_works_that_are_still_in_copyright(): void
    {
        // Guernica is in the corpus (Picasso died 1973) and must never reach a lesson.
        $this->assertStringContainsString('"pd_likely" = ?', Artwork::search('guernica')->toSql());
    }

    /** A lone trailing backslash used to throw "LIKE pattern must not end with escape character". */
    public function test_a_backslash_in_the_term_does_not_break_the_query(): void
    {
        $bindings = Artwork::search('rembrandt\\')->getBindings();

        $this->assertContains('%rembrandt\\\\%', $bindings);
    }
}
