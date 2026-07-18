<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Corrections for Cliopatria polygons whose Wikidata column carries the wrong item, read from the
 * committed database/data/cliopatria-qid-overrides.json (see its header for the two override
 * shapes). Cliopatria's mistags surface as wrong Wikipedia panels — e.g. its whole Kingdom-of-
 * France lineage (990–1847) is keyed to Q207162 "Bourbon Restoration in France", so clicking
 * medieval France showed the 1815 restoration article.
 */
class CliopatriaQidOverrides
{
    /** @var list<array{qid:string,name?:string,use:string,from?:int,to?:int,note?:string}>|null */
    private ?array $entries = null;

    /**
     * The Wikidata QID to enrich a Cliopatria feature from. A name-specific entry wins over a
     * QID-only entry; with no match the tile QID passes through unchanged.
     */
    public function resolve(string $qid, ?string $name = null): string
    {
        $byQid = null;
        foreach ($this->entries() as $entry) {
            if ($entry['qid'] !== $qid) {
                continue;
            }
            if (! isset($entry['name'])) {
                $byQid = $entry['use'];
            } elseif ($name !== null && $entry['name'] === $name) {
                return $entry['use'];
            }
        }

        return $byQid ?? $qid;
    }

    /**
     * Name-conditional entries only — the carved-out polity variants that need their own
     * enrichment row keyed by the target QID (the map rewrites clicks to it).
     *
     * @return list<array{qid:string,name:string,use:string,from?:int,to?:int,note?:string}>
     */
    public function namedEntries(): array
    {
        return array_values(array_filter(
            $this->entries(),
            fn (array $entry): bool => isset($entry['name']) && $entry['use'] !== $entry['qid'],
        ));
    }

    /** @return list<array{qid:string,name?:string,use:string,from?:int,to?:int,note?:string}> */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $path = database_path('data/cliopatria-qid-overrides.json');
        $decoded = File::exists($path) ? json_decode(File::get($path), true) : null;
        $list = is_array($decoded) ? ($decoded['overrides'] ?? null) : null;

        $out = [];
        if (is_array($list)) {
            foreach ($list as $entry) {
                if (is_array($entry) && is_string($entry['qid'] ?? null) && is_string($entry['use'] ?? null)) {
                    $out[] = $entry;
                }
            }
        }

        return $this->entries = $out;
    }
}
