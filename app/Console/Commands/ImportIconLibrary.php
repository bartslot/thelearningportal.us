<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SvgAsset;
use App\Services\Svg\SvgSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Publishes the icon set that ships in resources/icons into the shared library the Icons
 * panel reads — sanitising every file on the way through, exactly as a teacher's own import
 * is sanitised.
 *
 * The directory tree IS the taxonomy, so adding icons is a matter of dropping files in:
 *
 *   resources/icons/<collection>/<category>/<subcategory>/*.svg   →  line-art
 *   resources/icons/<collection>/*.svg                            →  arrows, shapes
 *
 * Re-running is safe: each file is keyed on its path, so an edited icon is replaced in
 * place and the scenes already using it keep pointing at the same asset id.
 */
class ImportIconLibrary extends Command
{
    protected $signature = 'icons:import
        {--path= : the icon set to publish (default: resources/icons)}
        {--collection=* : only these collections (default: every folder under the path)}
        {--license=CC BY 3.0 : licence recorded on every icon imported this run}
        {--attribution=The Noun Project : credit line recorded on every icon imported this run}
        {--prune : delete library icons whose source file has gone}';

    protected $description = 'Publish resources/icons into the shared icon library used by the Icons panel';

    /** Where the sanitised copies live on the public disk. */
    private const DISK_ROOT = 'svg-assets/library';

    public function handle(SvgSanitizer $sanitizer): int
    {
        $root = (string) ($this->option('path') ?: resource_path('icons'));
        if (! is_dir($root)) {
            $this->error("No icon set at {$root}.");

            return self::FAILURE;
        }

        $only = array_filter((array) $this->option('collection'));
        $collections = collect(scandir($root) ?: [])
            ->reject(fn (string $name) => str_starts_with($name, '.'))
            ->filter(fn (string $name) => is_dir($root.'/'.$name))
            ->when($only !== [], fn ($c) => $c->filter(fn (string $name) => in_array($name, $only, true)))
            ->values();

        if ($collections->isEmpty()) {
            $this->error('No collections to import.');

            return self::FAILURE;
        }

        $seen = [];
        $imported = 0;
        $failed = 0;

        foreach ($collections as $collection) {
            foreach ($this->svgFiles($root.'/'.$collection) as $file) {
                $ref = $collection.'/'.str_replace('\\', '/', $file->getRelativePathname());
                $seen[] = $ref;

                try {
                    $this->importOne($sanitizer, $collection, $file, $ref);
                    $imported++;
                } catch (RuntimeException $e) {
                    // One malformed file must not abort a 128-file publish — name it and carry on.
                    $failed++;
                    $this->warn("  skipped {$ref}: {$e->getMessage()}");
                }
            }
            $this->line("  {$collection}");
        }

        $this->info("Imported {$imported} icon(s)".($failed > 0 ? ", {$failed} skipped" : '').'.');

        if ($this->option('prune')) {
            $this->pruneMissing($seen, $collections->all());
        }

        return self::SUCCESS;
    }

    /** @return iterable<SplFileInfo> */
    private function svgFiles(string $dir): iterable
    {
        return Finder::create()->files()->in($dir)->name('*.svg')->sortByName();
    }

    private function importOne(SvgSanitizer $sanitizer, string $collection, SplFileInfo $file, string $ref): void
    {
        $raw = file_get_contents($file->getPathname());
        if ($raw === false) {
            throw new RuntimeException('unreadable');
        }

        $clean = $sanitizer->sanitize($raw);

        // Mirror the source tree on the disk so a stored file is traceable back to its icon.
        $path = self::DISK_ROOT.'/'.$this->diskPath($ref);
        Storage::disk('public')->put($path, $clean['svg']);

        // NOT updateOrCreate: its attribute match compiles `user_id = null`, which no row ever
        // satisfies in SQL, so every run would insert a duplicate instead of updating.
        $asset = SvgAsset::query()->whereNull('user_id')->where('source', 'bundled')->where('source_ref', $ref)->first()
            ?? new SvgAsset(['user_id' => null, 'source' => 'bundled', 'source_ref' => $ref]);

        [$category, $subcategory] = $this->taxonomy($file);

        $asset->fill([
            'collection' => $collection,
            'category' => $category,
            'subcategory' => $subcategory,
            'source_url' => '',
            'title' => $this->title($file->getFilenameWithoutExtension()),
            'license' => (string) $this->option('license'),
            'attribution' => (string) $this->option('attribution') ?: null,
            'svg_path' => $path,
            'width' => $clean['width'],
            'height' => $clean['height'],
            'view_box' => $clean['view_box'],
        ])->save();
    }

    /**
     * The folders between the collection and the file are the two pill rows.
     * A flat collection (arrows, shapes) simply has neither.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function taxonomy(SplFileInfo $file): array
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $file->getRelativePath()))));

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    /** Slugified mirror of the source path, so folder names with spaces and & stay filesystem-safe. */
    private function diskPath(string $ref): string
    {
        $parts = explode('/', $ref);
        $filename = array_pop($parts);

        return implode('/', array_map(fn (string $p) => Str::slug($p), $parts))
            .'/'.Str::slug(pathinfo($filename, PATHINFO_FILENAME)).'.svg';
    }

    /**
     * A readable name out of a stock-icon filename:
     *   np_stone-age-ax_700621_000000  →  Stone age ax
     *   noun-pharaoh-8159984           →  Pharaoh
     */
    private function title(string $filename): string
    {
        $name = preg_replace('/^(np|noun)[_-]/i', '', $filename) ?? $filename;
        $name = preg_replace('/[_-]\d+(?=([_-]\d+)*$)/', '', $name) ?? $name;   // trailing id segments
        $name = trim(str_replace(['_', '-'], ' ', $name));

        return $name === '' ? 'Icon' : Str::ucfirst($name);
    }

    /**
     * Drop library rows whose source file no longer exists. Only ever touches bundled rows in
     * the collections this run covered, so a partial import can't wipe the rest of the library.
     *
     * @param  list<string>  $seen
     * @param  list<string>  $collections
     */
    private function pruneMissing(array $seen, array $collections): void
    {
        $stale = SvgAsset::query()
            ->bundled()
            ->whereIn('collection', $collections)
            ->whereNotIn('source_ref', $seen)
            ->get();

        foreach ($stale as $asset) {
            Storage::disk('public')->delete($asset->svg_path);
            $asset->delete();
        }

        if ($stale->isNotEmpty()) {
            $this->info("Pruned {$stale->count()} icon(s) whose source file has gone.");
        }
    }
}
