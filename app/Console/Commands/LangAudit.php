<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Reports how much of the interface each language actually covers.
 *
 * The catalogue is every string the code asks Laravel to translate — `__('…')` and `@lang('…')`
 * across app/ and resources/views. A language is complete when lang/{code}.json has a key for all
 * of them; anything missing renders in English, which is the thing this command exists to find.
 *
 *   php artisan lang:audit                 coverage table for every language
 *   php artisan lang:audit --locale=de     list what German is still missing
 *   php artisan lang:audit --area=wizard   narrow to one surface
 *   php artisan lang:audit --json          machine-readable, used by the coverage test
 *   php artisan lang:audit --prune         drop keys no longer used anywhere in the code
 */
class LangAudit extends Command
{
    protected $signature = 'lang:audit
        {--locale= : List the missing strings for one language}
        {--area= : Only strings from one surface (teacher, wizard, player, admin, pdf, email, landing)}
        {--json : Output JSON instead of a table}
        {--prune : Remove translation keys that no source string uses any more}';

    protected $description = 'Report translation coverage per language, and list what is missing';

    /** Scanned for translatable strings, in this order. */
    private const SOURCE_PATHS = ['app', 'resources/views'];

    public function handle(): int
    {
        $catalogue = $this->catalogue();

        if ($catalogue === []) {
            $this->error('No translatable strings found — has the extractor broken?');

            return self::FAILURE;
        }

        $area = $this->option('area');
        $strings = $area
            ? array_keys(array_filter($catalogue, fn (array $areas) => in_array($area, $areas, true)))
            : array_keys($catalogue);

        if ($area && $strings === []) {
            $this->error("Unknown area '{$area}'. Known: ".implode(', ', $this->knownAreas($catalogue)));

            return self::FAILURE;
        }

        $report = [];

        foreach (Locales::codes() as $code) {
            if ($code === 'en') {
                continue; // English is the source; the strings are the translation.
            }

            $translations = $this->translations($code);
            $missing = array_values(array_filter($strings, fn (string $s) => ! isset($translations[$s])));
            $unused = array_values(array_diff(array_keys($translations), array_keys($catalogue)));

            $report[$code] = [
                'name' => Locales::name($code),
                'total' => count($strings),
                'translated' => count($strings) - count($missing),
                'missing' => $missing,
                'unused' => $unused,
            ];
        }

        if ($this->option('prune')) {
            return $this->prune($report);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($locale = $this->option('locale')) {
            return $this->listMissing($locale, $report);
        }

        $this->table(
            ['Language', 'Translated', 'Missing', 'Coverage', 'Unused keys'],
            array_map(fn (string $code, array $row) => [
                "{$row['name']} ({$code})",
                $row['translated'].' / '.$row['total'],
                count($row['missing']),
                $this->percentage($row).'%',
                count($row['unused']),
            ], array_keys($report), $report),
        );

        $worst = min(array_map(fn (array $row) => $this->percentage($row), $report));

        $this->newLine();
        $this->line($worst === 100.0
            ? '<info>Every language covers the whole interface.</info>'
            : '<comment>Run with --locale=xx to see what is missing.</comment>');

        return self::SUCCESS;
    }

    /**
     * Every translatable source string, mapped to the surfaces it appears on.
     *
     * @return array<string,list<string>>
     */
    public function catalogue(): array
    {
        $pattern = "/(?:__|@lang)\(\s*'((?:\\\\.|[^'\\\\])*)'|(?:__|@lang)\(\s*\"((?:\\\\.|[^\"\\\\])*)\"/";
        $catalogue = [];

        foreach (self::SOURCE_PATHS as $path) {
            foreach (File::allFiles(base_path($path)) as $file) {
                if (! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);

                if ($matches === []) {
                    continue;
                }

                $area = $this->areaFor(str_replace(base_path().'/', '', $file->getPathname()));

                foreach ($matches as $match) {
                    $raw = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

                    if ($raw === '') {
                        continue;
                    }

                    $string = stripcslashes($raw);

                    // Nothing to translate in whitespace, arrows or bare punctuation — counting
                    // them as missing would keep coverage permanently short of 100%.
                    if (! preg_match('/\p{L}/u', $string)) {
                        continue;
                    }
                    $catalogue[$string] = array_values(array_unique([...($catalogue[$string] ?? []), $area]));
                }
            }
        }

        ksort($catalogue);

        return $catalogue;
    }

    /** Which surface a file belongs to, so coverage can be chased one screen at a time. */
    private function areaFor(string $relativePath): string
    {
        return match (true) {
            str_contains($relativePath, 'Admin') || str_contains($relativePath, 'views/admin') => 'admin',
            str_contains($relativePath, 'views/pdf') => 'pdf',
            str_contains($relativePath, 'views/emails') || str_contains($relativePath, 'app/Mail') => 'email',
            str_contains($relativePath, 'landing') || str_contains($relativePath, 'views/about') => 'landing',
            str_contains($relativePath, 'wizard') || str_contains($relativePath, 'Wizard') => 'wizard',
            str_contains($relativePath, 'player') || str_contains($relativePath, 'views/lesson') => 'player',
            default => 'teacher',
        };
    }

    /** @return array<string,string> */
    private function translations(string $code): array
    {
        $path = lang_path($code.'.json');

        if (! File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR) ?: [];
    }

    /** @param array<string,array{name:string,total:int,translated:int,missing:list<string>,unused:list<string>}> $report */
    private function listMissing(string $locale, array $report): int
    {
        if (! isset($report[$locale])) {
            $this->error("Unknown language '{$locale}'. Known: ".implode(', ', array_keys($report)));

            return self::FAILURE;
        }

        $missing = $report[$locale]['missing'];

        $this->info("{$report[$locale]['name']} is missing ".count($missing).' of '.$report[$locale]['total'].' strings:');

        foreach ($missing as $string) {
            $this->line('  '.$string);
        }

        return self::SUCCESS;
    }

    /** @param array<string,array{unused:list<string>}> $report */
    private function prune(array $report): int
    {
        foreach ($report as $code => $row) {
            if ($row['unused'] === []) {
                continue;
            }

            $translations = $this->translations($code);

            foreach ($row['unused'] as $key) {
                unset($translations[$key]);
            }

            ksort($translations);
            File::put(
                lang_path($code.'.json'),
                json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );

            $this->line('Pruned '.count($row['unused'])." unused keys from lang/{$code}.json");
        }

        return self::SUCCESS;
    }

    /** @param array{total:int,translated:int} $row */
    private function percentage(array $row): float
    {
        return $row['total'] === 0 ? 100.0 : round($row['translated'] / $row['total'] * 100, 1);
    }

    /** @param array<string,list<string>> $catalogue */
    private function knownAreas(array $catalogue): array
    {
        return array_values(array_unique(array_merge(...array_values($catalogue))));
    }
}
