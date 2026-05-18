<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AnimationClip;
use App\Models\AvatarAnimationController;
use Illuminate\Database\Seeder;

class AnimationLibrarySeeder extends Seeder
{
    private const LIB = 'avatars/animation-library';

    private const CATEGORIES = ['idle', 'expression', 'dance'];

    // Filename prefix → gender value stored in DB
    private const GENDER_PREFIXES = [
        'F_' => 'feminine',
        'M_' => 'masculine',
    ];

    // Subfolder that is canonical for each gender (both contain both genders,
    // we just use the matching one to keep paths intuitive).
    private const GENDER_DIRS = [
        'feminine'  => 'feminine',
        'masculine' => 'masculine',
    ];

    public function run(): void
    {
        // ── 1. Clear old clips ─────────────────────────────────────────────────
        AnimationClip::truncate();

        // ── 2. Reset all controller data to new category schema ────────────────
        AvatarAnimationController::all()->each(function (AvatarAnimationController $ctrl): void {
            $ctrl->update(['controller' => AvatarAnimationController::defaultControllerData()]);
        });

        // ── 3. Index library files ─────────────────────────────────────────────
        $rows = [];

        foreach (self::GENDER_DIRS as $gender => $dir) {
            $prefix = $gender === 'feminine' ? 'F_' : 'M_';

            foreach (self::CATEGORIES as $cat) {
                $absDir = public_path(self::LIB . "/{$dir}/glb/{$cat}");
                if (! is_dir($absDir)) {
                    continue;
                }

                $files = array_values(array_filter(
                    glob("{$absDir}/*.glb") ?: [],
                    fn (string $f) => str_starts_with(basename($f), $prefix),
                ));
                sort($files);

                foreach ($files as $sortOrder => $absPath) {
                    $filename = basename($absPath);
                    $stem     = pathinfo($filename, PATHINFO_FILENAME);

                    $glbPath  = self::LIB . "/{$dir}/glb/{$cat}/{$filename}";
                    $webpRel  = self::LIB . "/{$dir}/webp/{$cat}/{$stem}.webp";
                    $thumbPath = file_exists(public_path($webpRel)) ? $webpRel : null;

                    $rows[] = [
                        'name'           => self::prettifyName($stem),
                        'category'       => $cat,
                        'gender'         => $gender,
                        'glb_path'       => $glbPath,
                        'fbx_path'       => null,
                        'thumbnail_path' => $thumbPath,
                        'sort_order'     => $sortOrder,
                        'speed'          => 1.0,
                        'expressiveness' => 1.0,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                }
            }
        }

        // Bulk insert in one query
        foreach (array_chunk($rows, 50) as $chunk) {
            AnimationClip::insert($chunk);
        }

        $total = count($rows);
        $this->command->info("Indexed {$total} animation clips from the RPM library.");
    }

    // ── Name formatting ────────────────────────────────────────────────────────

    private static function prettifyName(string $stem): string
    {
        // Remove gender prefix (F_ / M_)
        $name = (string) preg_replace('/^[FM]_/', '', $stem);

        // Underscores → spaces
        $name = str_replace('_', ' ', $name);

        // Collapse padded numbers: 001 → 1, 004 → 4
        $name = (string) preg_replace_callback('/\b(\d{2,3})\b/', fn ($m) => (string) (int) $m[1], $name);

        // Shorten common patterns (longest first to avoid partial matches)
        $shorthands = [
            'Standing Idle Variations' => 'Idle Variation',
            'Standing Expressions'     => 'Expression',
            'Talking Variations'       => 'Talking',
            'Standing Idle'            => 'Standing Idle',   // keep as-is, already nice
            'Dances'                   => 'Dance',
        ];

        foreach ($shorthands as $from => $to) {
            if (str_contains($name, $from)) {
                $name = str_replace($from, $to, $name);
                break;
            }
        }

        return trim($name);
    }
}
