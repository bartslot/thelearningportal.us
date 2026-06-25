<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * The on-map lifespan of every Cliopatria polity, keyed by Wikidata QID, read from the committed
 * list (database/data/cliopatria-polities.json).
 *
 * This is the SAME span the border tiles render by (FromYear/ToYear), so it is the authoritative
 * source for a polity's era. The panel's inception/dissolution must mirror it — otherwise a
 * lineage polity reads inconsistently: e.g. Cliopatria draws "Kingdom of Italy" (Q172579) from
 * 587 CE, but Wikidata's P571 inception is the 1861 *modern* kingdom, so the panel would claim
 * 1861 while the territory appears on the map centuries earlier.
 */
class CliopatriaSpans
{
    /** @var array<string,array{from:?int,to:?int}>|null Memoised qid => span. */
    private ?array $spans = null;

    /** The lifespan for a QID, or null if it is not a Cliopatria polity. */
    public function for(string $qid): ?array
    {
        return $this->all()[$qid] ?? null;
    }

    /** @return array<string,array{from:?int,to:?int}> qid => {from,to} */
    public function all(): array
    {
        if ($this->spans !== null) {
            return $this->spans;
        }

        $path = database_path('data/cliopatria-polities.json');
        $list = File::exists($path) ? json_decode(File::get($path), true) : null;

        $out = [];
        if (is_array($list)) {
            foreach ($list as $row) {
                $qid = $row['qid'] ?? null;
                if (is_string($qid) && $qid !== '') {
                    $out[$qid] = [
                        'from' => isset($row['from']) ? (int) $row['from'] : null,
                        'to' => isset($row['to']) ? (int) $row['to'] : null,
                    ];
                }
            }
        }

        return $this->spans = $out;
    }
}
