<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CliopatriaQidOverrides;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CliopatriaQidOverridesTest extends TestCase
{
    public function test_qid_only_override_applies_to_every_name(): void
    {
        $overrides = new CliopatriaQidOverrides;

        // The reported bug: Cliopatria keys its whole Kingdom-of-France lineage to the
        // Bourbon Restoration item; every segment must enrich from the Kingdom of France.
        $this->assertSame('Q70972', $overrides->resolve('Q207162'));
        $this->assertSame('Q70972', $overrides->resolve('Q207162', 'Kingdom of France'));
        $this->assertSame('Q70972', $overrides->resolve('Q207162', 'Bourbon Kingdom of France'));
    }

    public function test_name_entry_splits_a_shared_qid(): void
    {
        $overrides = new CliopatriaQidOverrides;

        // Q1068371 is genuinely the Chauhan dynasty; only the Han Dynasty polygons are rewritten.
        $this->assertSame('Q7209', $overrides->resolve('Q1068371', 'Han Dynasty'));
        $this->assertSame('Q1068371', $overrides->resolve('Q1068371', 'Chauhan Dynasty'));
        $this->assertSame('Q1068371', $overrides->resolve('Q1068371'));
    }

    /**
     * Cliopatria tags its 500 BCE Roman Republic polygons with Q175881, which is the Roman Republic
     * of 1798 — Napoleon's sister republic of the First French Republic. The panel faithfully
     * fetched that article, its tricolour, and its 1799 end date, and presented all three over a
     * map of the Mediterranean at 33 BCE.
     *
     * Worth pinning by QID rather than by name: the two items share a label exactly, so "Roman
     * Republic" tells you nothing about which one you have.
     */
    public function test_the_ancient_roman_republic_is_not_the_one_napoleon_made(): void
    {
        $overrides = new CliopatriaQidOverrides;

        $this->assertSame('Q17167', $overrides->resolve('Q175881', 'Roman Republic'));
        $this->assertSame('Q17167', $overrides->resolve('Q175881'));
    }

    public function test_unlisted_qid_passes_through(): void
    {
        $this->assertSame('Q12536', (new CliopatriaQidOverrides)->resolve('Q12536', 'Abbasid Caliphate'));
    }

    public function test_every_entry_is_well_formed(): void
    {
        $entries = (new CliopatriaQidOverrides)->entries();

        $this->assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^Q\d+$/', $entry['qid']);
            $this->assertMatchesRegularExpression('/^Q\d+$/', $entry['use']);
        }
    }

    public function test_polity_list_keeps_the_keeper_variant_for_named_entries(): void
    {
        // The list row for a shared QID must describe the polity the QID genuinely is — the
        // carved-out variant lives in a standalone row. Guards against a regenerated
        // cliopatria-polities.json silently undoing the split.
        $list = collect(json_decode(File::get(database_path('data/cliopatria-polities.json')), true))
            ->keyBy('qid');

        foreach ((new CliopatriaQidOverrides)->namedEntries() as $entry) {
            $row = $list->get($entry['qid']);
            $this->assertNotNull($row, "{$entry['qid']} missing from cliopatria-polities.json");
            $this->assertNotSame(
                $entry['name'],
                $row['name'],
                "{$entry['qid']} list row still bears the carved-out name '{$entry['name']}' — re-apply the keeper polity (see cliopatria-qid-overrides.json)."
            );
        }
    }
}
