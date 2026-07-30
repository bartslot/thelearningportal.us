<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Corpus\TopicGrounding;
use PHPUnit\Framework\TestCase;

/**
 * Matching a teacher's typed topic against our own catalog.
 *
 * Nothing here is shown to anyone before it is used — a wrong pick silently grounds a
 * whole lesson in the wrong article, and the "only use facts from the source" rule then
 * faithfully teaches the wrong subject. So these lean on the side of refusing to match.
 */
class TopicGroundingTest extends TestCase
{
    /** A catalog row, shaped like the public.topics view. */
    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'event:Q123',
            'type' => 'event',
            'name' => 'Dutch Golden Age',
            'qid' => 'Q123',
            'wikipedia_url' => 'https://nl.wikipedia.org/wiki/Gouden_Eeuw',
            'summary' => 'A period of Dutch trade, science and art.',
            'aliases' => 'Gouden Eeuw|Nederlandse Gouden Eeuw',
            'era_start' => 1588,
            'era_end' => 1672,
            'region_label' => 'Dutch Republic',
            'source_attribution' => 'Wikipedia (CC-BY-SA)',
        ], $overrides);
    }

    public function test_an_exact_name_match_grounds_the_lesson(): void
    {
        $hit = TopicGrounding::pick([$this->row()], 'Dutch Golden Age');

        $this->assertNotNull($hit);
        $this->assertSame('https://nl.wikipedia.org/wiki/Gouden_Eeuw', $hit->wikipediaUrl);
        $this->assertSame(1.0, $hit->confidence);
    }

    /** Teachers type Dutch topics with articles and capitals; the catalog stores neither. */
    public function test_dutch_articles_accents_and_case_do_not_block_a_match(): void
    {
        $this->assertNotNull(TopicGrounding::pick([$this->row()], 'de gouden eeuw'));
        $this->assertNotNull(TopicGrounding::pick([$this->row()], 'GOUDEN EEUW'));
        $this->assertNotNull(TopicGrounding::pick([$this->row(['name' => 'Willem van Oranje', 'aliases' => ''])], 'willem van oranje'));
    }

    public function test_an_alias_counts_as_an_exact_match(): void
    {
        $hit = TopicGrounding::pick([$this->row()], 'Nederlandse Gouden Eeuw');

        $this->assertNotNull($hit);
        $this->assertSame(1.0, $hit->confidence);
    }

    /**
     * The catalog's own ranking always returns something — it is built for a dropdown where a
     * near-miss is helpful. Here a near-miss is a wrong lesson.
     */
    public function test_a_merely_plausible_hit_is_refused(): void
    {
        $candidates = [
            $this->row(['name' => 'Óscar Romero', 'type' => 'figure', 'aliases' => '']),
            $this->row(['name' => 'Prome Kingdom', 'type' => 'polity', 'aliases' => '']),
        ];

        $this->assertNull(TopicGrounding::pick($candidates, 'Rome'));
    }

    public function test_the_closest_of_several_candidates_wins(): void
    {
        $candidates = [
            $this->row(['name' => 'Roman Republic', 'qid' => 'Q1', 'aliases' => '']),
            $this->row(['name' => 'Roman Empire', 'qid' => 'Q2', 'aliases' => '']),
        ];

        $hit = TopicGrounding::pick($candidates, 'Roman Empire');

        $this->assertSame('Q2', $hit?->qid);
    }

    /** "Utrecht" in a 1600s lesson is not a polity that dissolved in 800. */
    public function test_a_candidate_from_the_wrong_century_is_refused(): void
    {
        $medieval = $this->row(['name' => 'Utrecht', 'aliases' => '', 'era_start' => 650, 'era_end' => 800]);

        $this->assertNull(TopicGrounding::pick([$medieval], 'Utrecht', 1600));
        $this->assertNotNull(TopicGrounding::pick([$medieval], 'Utrecht', 720), 'a lesson inside its dates still matches');
        $this->assertNotNull(TopicGrounding::pick([$medieval], 'Utrecht'), 'no era on the lesson means no era filter');
    }

    public function test_an_entry_without_dates_is_never_filtered_out_by_era(): void
    {
        $undated = $this->row(['name' => 'Rembrandt', 'aliases' => '', 'era_start' => null, 'era_end' => null]);

        $this->assertNotNull(TopicGrounding::pick([$undated], 'Rembrandt', 1650));
    }

    public function test_an_empty_catalog_or_empty_topic_grounds_nothing(): void
    {
        $this->assertNull(TopicGrounding::pick([], 'Dutch Golden Age'));
        $this->assertNull(TopicGrounding::pick([$this->row()], '   '));
    }

    public function test_the_source_block_carries_the_facts_the_prompt_needs(): void
    {
        $block = TopicGrounding::pick([$this->row()], 'Dutch Golden Age')?->sourceBlock() ?? '';

        $this->assertStringContainsString('Dutch Golden Age', $block);
        $this->assertStringContainsString('1588 CE – 1672 CE', $block);
        $this->assertStringContainsString('Dutch Republic', $block);
        $this->assertStringContainsString('A period of Dutch trade', $block);
        $this->assertStringContainsString('Wikipedia (CC-BY-SA)', $block);
    }

    public function test_bce_dates_read_as_bce(): void
    {
        $hit = TopicGrounding::pick([$this->row(['era_start' => -509, 'era_end' => -27])], 'Dutch Golden Age');

        $this->assertStringContainsString('509 BCE – 27 BCE', $hit?->sourceBlock() ?? '');
    }

    /** Era labels reach us as prose, not numbers. */
    public function test_a_year_is_read_out_of_a_human_era_label(): void
    {
        $this->assertSame(1650, TopicGrounding::eraYear('Early modern period (1500–1800)'));
        $this->assertSame(1651, TopicGrounding::eraYear('1642 – 1659'));
        $this->assertSame(1600, TopicGrounding::eraYear('1600'));
        $this->assertNull(TopicGrounding::eraYear('17e eeuw'));
        $this->assertNull(TopicGrounding::eraYear(''));
    }
}
