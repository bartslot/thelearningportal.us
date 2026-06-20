<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\AuditTopicUrls;
use PHPUnit\Framework\TestCase;

class AuditTopicUrlsTest extends TestCase
{
    public function test_article_title_extracts_decoded_slug(): void
    {
        $this->assertSame(
            'Byzantine_Empire',
            AuditTopicUrls::articleTitle('https://en.wikipedia.org/wiki/Byzantine_Empire'),
        );
    }

    public function test_matching_name_and_article_share_a_significant_word(): void
    {
        $this->assertTrue(AuditTopicUrls::sharesSignificantWord('Ottoman Empire', 'Ottoman_Empire'));
        $this->assertTrue(AuditTopicUrls::sharesSignificantWord('Mongol Empire', 'Mongol_Empire'));
    }

    public function test_real_mislabel_shares_no_significant_word(): void
    {
        // Q12544 is the Byzantine Empire on Wikidata but is labelled "Roman Empire" in the catalog.
        $this->assertFalse(AuditTopicUrls::sharesSignificantWord('Roman Empire', 'Byzantine_Empire'));
        // Dutch Republic grounded in Kingdom_of_France — clearly the wrong QID.
        $this->assertFalse(AuditTopicUrls::sharesSignificantWord('Dutch Republic', 'Kingdom_of_France'));
    }

    public function test_stopwords_alone_do_not_count_as_a_match(): void
    {
        // Both contain only the shared stopword "empire" — must NOT be treated as a match.
        $this->assertFalse(AuditTopicUrls::sharesSignificantWord('Roman Empire', 'Mongol_Empire'));
    }
}
