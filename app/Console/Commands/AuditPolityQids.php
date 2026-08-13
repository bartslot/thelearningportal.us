<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CliopatriaQidOverrides;
use App\Services\HandTerritories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Finds Cliopatria polities pointing at the wrong Wikidata item.
 *
 * The bug this exists for, found by Bart: clicking Rome at 33 BCE opened "Roman Republic,
 * 500 BCE – 1799 CE" with a tricolour flag and an article about Napoleon's sister republic of the
 * First French Republic. Cliopatria had tagged its ancient Roman Republic polygons with Q175881
 * (the 1798 one) instead of Q17167. The panel was working perfectly; the data was wrong.
 *
 * THE SIGNAL. A polity's polygons carry FromYear/ToYear, and its Wikidata item carries its own
 * dates. When those two eras do not overlap AT ALL, the polygon and the item are not describing the
 * same thing. That is a mechanical check, and it does not care how plausible the label looks —
 * "Roman Republic" is the correct name for both items, which is exactly why a human reading the
 * name never catches it.
 *
 * The 60 hand-written entries in cliopatria-qid-overrides.json are the control: this should
 * rediscover them. If it does not, the check is too lax, and anything it finds beyond them is a
 * candidate nobody has looked at yet.
 *
 * It audits the HAND-AUTHORED layer too (database/data/hand-territories.json), and that half needs
 * it more, not less: those QIDs were looked up and typed by a person, which is exactly the mistake
 * Cliopatria made with the Roman Republic. Nothing about the check changes for them — a mechanical
 * era comparison has to treat every polity identically or it stops being mechanical — only where
 * the fix goes when it finds something.
 */
final class AuditPolityQids extends Command
{
    protected $signature = 'timemap:audit-polity-qids
        {--limit=0 : Stop after this many distinct QIDs (0 = all)}
        {--slack=75 : Years of tolerance before an era gap counts as a mismatch}
        {--json= : Write the findings to this path}';

    protected $description = 'Flag polities — imported or hand-authored — whose Wikidata item belongs to a different era';

    /** Wikidata takes 50 ids per request; more than that is rejected. */
    private const BATCH = 50;

    /** When a thing BEGAN. */
    private const START_PROPS = ['P571', 'P580'];

    /** When it ENDED. */
    private const END_PROPS = ['P576', 'P582'];

    /** A single moment, which bounds both ends. */
    private const POINT_PROPS = ['P585'];

    public function handle(CliopatriaQidOverrides $overrides, HandTerritories $hand): int
    {
        $source = storage_path('app/cliopatria/cliopatria_polities_only.geojson');
        $polities = [];

        // The Cliopatria source is a 165 MB download that is deliberately not committed, so it is
        // absent in a fresh clone and in every worktree. That used to abort the whole command —
        // which meant "run the audit after any data change" was impossible to obey while working on
        // the hand-authored layer, the one part of the data a person actually edits by hand.
        //
        // So a missing source now skips Cliopatria and audits what IS present, loudly.
        if (File::exists($source)) {
            $this->info('Reading polity eras…');
            $polities = $this->politiesFrom($source);
            $this->line('  '.count($polities).' distinct (qid, name) polities');
        } else {
            $this->warn("No Cliopatria source at {$source} — auditing the hand-authored layer only.");
            $this->line('  Run timemap:build-cliopatria-tiles to include the imported borders.');
        }

        // The hand-authored supplement gets the same mechanical check, and needs it more: these
        // shapes are typed by a person against a QID they looked up, which is exactly how the
        // Roman Republic came to be tagged with Napoleon's sister republic of 1798.
        $handPolities = $this->handPolities($hand);
        $this->line('  '.count($handPolities).' hand-authored territories');
        $polities = [...$polities, ...$handPolities];

        if ($polities === []) {
            $this->error('Nothing to audit.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $polities = array_slice($polities, 0, $limit, true);
        }

        $qids = array_values(array_unique(array_map(fn (array $p) => $p['qid'], $polities)));
        $this->info('Fetching '.count($qids).' Wikidata items…');
        $itemEras = $this->wikidataEras($qids);

        $slack = (int) $this->option('slack');
        $findings = [];

        foreach ($polities as $polity) {
            $era = $itemEras[$polity['qid']] ?? null;
            if ($era === null || ($era['from'] === null && $era['to'] === null)) {
                continue; // no dates on the item, so nothing to contradict
            }

            // Overlap, with slack, against a possibly half-open item era. A missing end means "still
            // going", a missing start means "already there" — neither can contradict a polygon.
            $afterItem = $era['to'] === null ? PHP_INT_MIN : $polity['from'] - $era['to'];
            $beforeItem = $era['from'] === null ? PHP_INT_MIN : $era['from'] - $polity['to'];
            $gap = max($afterItem, $beforeItem);
            if ($gap <= $slack) {
                continue;
            }

            $hand = $polity['hand'] ?? false;
            // A Cliopatria override CANNOT cover a hand-authored territory. The override table
            // rewrites which item a Cliopatria TILE resolves to; our own JSON is read straight and
            // never passes through it. Asking it anyway is not merely useless — it silences us:
            // point a hand-authored territory at Q175881 and the audit reported the mismatch as
            // "already fixed by an override", because Cliopatria happens to have that same QID
            // corrected. The one finding that is genuinely ours to fix was the one hidden.
            $already = ! $hand && $overrides->resolve($polity['qid'], $polity['name']) !== $polity['qid'];
            $findings[] = [
                'qid' => $polity['qid'],
                'name' => $polity['name'],
                // A hand-authored mismatch is OURS to fix in database/data/hand-territories.json,
                // not with a Cliopatria override — so the report has to say which it is.
                'hand' => $hand,
                'polygon' => [$polity['from'], $polity['to']],
                'item' => [$era['from'], $era['to']],
                'item_open' => $era['from'] === null || $era['to'] === null,
                'item_label' => $era['label'],
                'gap_years' => $gap,
                'already_overridden' => $already,
            ];
        }

        usort($findings, fn ($a, $b) => $b['gap_years'] <=> $a['gap_years']);
        $this->report($findings);

        if ($path = $this->option('json')) {
            File::put($path, json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->line("  written to {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * The hand-authored territories, in the same shape as a Cliopatria one so they audit identically.
     *
     * `hand` on the record is only for the report line — the check itself does not care where a
     * polity came from, and it must not: the point of a mechanical era check is that it treats
     * everything the same way regardless of how plausible its label looks.
     *
     * @return array<string, array{qid: string, name: string, from: int, to: int, hand: bool}>
     */
    private function handPolities(HandTerritories $hand): array
    {
        $out = [];
        foreach ($hand->all() as $territory) {
            $out['hand:'.$territory['id']] = [
                'qid' => $territory['qid'],
                'name' => $territory['name'],
                'from' => (int) $territory['from'],
                'to' => (int) $territory['to'],
                'hand' => true,
            ];
        }

        return $out;
    }

    /** (qid, name) → widest polygon era in the source. One line per feature, so this streams. */
    private function politiesFrom(string $path): array
    {
        $out = [];
        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $start = strpos($line, '{');
            if ($start === false) {
                continue;
            }
            $feature = json_decode(rtrim(rtrim(trim(substr($line, $start)), ',')), true);
            $p = $feature['properties'] ?? null;
            if (! is_array($p) || empty($p['Wikidata'])) {
                continue;
            }

            $key = $p['Wikidata'].'|'.($p['Name'] ?? '');
            if (! isset($out[$key])) {
                $out[$key] = ['qid' => $p['Wikidata'], 'name' => (string) ($p['Name'] ?? ''), 'from' => $p['FromYear'], 'to' => $p['ToYear']];

                continue;
            }
            $out[$key]['from'] = min($out[$key]['from'], $p['FromYear']);
            $out[$key]['to'] = max($out[$key]['to'], $p['ToYear']);
        }

        fclose($handle);

        return $out;
    }

    /** QID → {from, to, label}, from whichever date properties the item happens to carry. */
    private function wikidataEras(array $qids): array
    {
        $eras = [];
        $bar = $this->output->createProgressBar((int) ceil(count($qids) / self::BATCH));

        foreach (array_chunk($qids, self::BATCH) as $chunk) {
            $response = Http::withHeaders(['User-Agent' => 'thelearningportal.us timemap audit'])
                ->timeout(60)
                ->retry(3, 500)
                ->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbgetentities',
                    'ids' => implode('|', $chunk),
                    'props' => 'claims|labels',
                    'languages' => 'en',
                    'format' => 'json',
                ]);

            foreach ($response->json('entities') ?? [] as $qid => $entity) {
                $starts = $this->yearsOf($entity, array_merge(self::START_PROPS, self::POINT_PROPS));
                $ends = $this->yearsOf($entity, array_merge(self::END_PROPS, self::POINT_PROPS));

                // An item with only ONE date bounds one end and leaves the other OPEN. Anglo-Saxon
                // England carries its 1066 dissolution and no inception; treating that as the point
                // 1066..1066 made every polygon before it look mistagged, which is a false positive
                // on a correctly tagged polity. Half a date is a half-open interval, not a moment.
                $eras[$qid] = [
                    'from' => $starts === [] ? null : min($starts),
                    'to' => $ends === [] ? null : max($ends),
                    'label' => $entity['labels']['en']['value'] ?? '?',
                ];
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $eras;
    }

    /** Every 4-digit-or-longer year on the given properties. */
    private function yearsOf(array $entity, array $props): array
    {
        $years = [];
        foreach ($props as $prop) {
            foreach ($entity['claims'][$prop] ?? [] as $claim) {
                $time = $claim['mainsnak']['datavalue']['value']['time'] ?? null;
                if ($time && preg_match('/^([+-])(\d{4,})/', $time, $m)) {
                    $years[] = (int) ($m[1] === '-' ? '-'.ltrim($m[2], '0') : ltrim($m[2], '0'));
                }
            }
        }

        return $years;
    }

    private function report(array $findings): void
    {
        $fresh = array_values(array_filter($findings, fn ($f) => ! $f['already_overridden']));
        $known = count($findings) - count($fresh);

        $this->newLine();
        $this->info(count($findings).' era mismatches — '.$known.' already fixed by an override, '.count($fresh).' NOT yet looked at');

        if ($known > 0) {
            $this->line('  (the known ones are the control: the check re-finds what humans found by hand)');
        }

        if ($fresh === []) {
            $this->info('  Nothing new. Every mismatch is already covered.');

            return;
        }

        // A hand-authored mismatch is fixed in a different file, by us, so it is called out rather
        // than left to be mistaken for one more thing Cliopatria got wrong.
        $ours = array_values(array_filter($fresh, fn ($f) => $f['hand'] ?? false));
        if ($ours !== []) {
            $this->error('  '.count($ours).' of these are OURS — fix them in database/data/hand-territories.json, not with a QID override:');
            foreach ($ours as $f) {
                $this->line("    {$f['name']} ({$f['qid']}) — we date it {$f['polygon'][0]}…{$f['polygon'][1]}, the item is “{$f['item_label']}”");
            }
        }

        $this->newLine();
        $this->table(
            ['polity', 'qid', 'polygon era', 'wikidata item', 'item era', 'gap', 'ours?'],
            array_map(fn ($f) => [
                mb_strimwidth($f['name'], 0, 30, '…'),
                $f['qid'],
                $f['polygon'][0].' … '.$f['polygon'][1],
                mb_strimwidth($f['item_label'], 0, 30, '…'),
                ($f['item'][0] ?? '?').' … '.($f['item'][1] ?? '?'),
                $f['gap_years'].'y',
                ($f['hand'] ?? false) ? 'hand' : '',
            ], array_slice($fresh, 0, 40)),
        );

        if (count($fresh) > 40) {
            $this->line('  … and '.(count($fresh) - 40).' more (use --json to get them all)');
        }
    }
}
