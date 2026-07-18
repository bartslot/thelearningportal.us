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
