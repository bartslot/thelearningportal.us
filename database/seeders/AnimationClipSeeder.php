<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AnimationClip;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AnimationClipSeeder extends Seeder
{
    // Base path inside public storage
    private const BASE = 'avatars/animation';

    // Friendly label overrides for known animation names
    private const LABELS = [
        'F_Dances_001'                  => 'Feminine Dance 1',
        'F_Dances_004'                  => 'Feminine Dance 4',
        'F_Dances_005'                  => 'Feminine Dance 5',
        'F_Dances_006'                  => 'Feminine Dance 6',
        'F_Dances_007'                  => 'Feminine Dance 7',
        'M_Dances_001'                  => 'Masculine Dance 1',
        'M_Dances_002'                  => 'Masculine Dance 2',
        'M_Dances_003'                  => 'Masculine Dance 3',
        'M_Dances_004'                  => 'Masculine Dance 4',
        'M_Dances_005'                  => 'Masculine Dance 5',
        'M_Dances_006'                  => 'Masculine Dance 6',
        'M_Dances_007'                  => 'Masculine Dance 7',
        'M_Dances_008'                  => 'Masculine Dance 8',
        'M_Dances_009'                  => 'Masculine Dance 9',
        'M_Dances_011'                  => 'Masculine Dance 11',
        'F_Standing_Idle_001'           => 'Feminine Idle',
        'M_Standing_Idle_001'           => 'Masculine Idle 1',
        'M_Standing_Idle_002'           => 'Masculine Idle 2',
    ];

    public function run(): void
    {
        AnimationClip::truncate();

        $sortOrder = 1;

        // Seed from masculine folder only — both folders have identical files.
        // Gender is determined by the M_/F_ filename prefix.
        foreach (['idle', 'expression', 'dance', 'locomotion'] as $category) {
            $glbDir  = storage_path("app/public/" . self::BASE . "/masculine/glb/{$category}");
            $webpDir = storage_path("app/public/" . self::BASE . "/masculine/webp/{$category}");

            if (! is_dir($glbDir)) {
                continue;
            }

            $files = collect(glob("{$glbDir}/*.glb") ?: [])
                ->map(fn ($f) => basename($f))
                ->sort()
                ->values();

            foreach ($files as $filename) {
                $stem   = pathinfo($filename, PATHINFO_FILENAME);
                $gender = str_starts_with($stem, 'F_') ? 'feminine' : 'masculine';
                $genderFolder = $gender; // masculine or feminine subfolder

                $glbPath       = self::BASE . "/{$genderFolder}/glb/{$category}/{$filename}";
                $thumbnailFile = $stem . '.webp';
                $thumbnailPath = file_exists("{$webpDir}/{$thumbnailFile}")
                    ? self::BASE . "/{$genderFolder}/webp/{$category}/{$thumbnailFile}"
                    : null;

                $name = self::LABELS[$stem] ?? self::humanize($stem);

                AnimationClip::create([
                    'name'           => $name,
                    'slug'           => Str::slug("{$stem}"),
                    'category'       => $category,
                    'gender'         => $gender,
                    'glb_path'       => $glbPath,
                    'thumbnail_path' => $thumbnailPath,
                    'fbx_path'       => null,
                    'sort_order'     => $sortOrder++,
                    'speed'          => 1.0,
                    'expressiveness' => 1.0,
                ]);
            }
        }

        $this->command->info("Seeded {$sortOrder} animation clips.");
    }

    private static function humanize(string $stem): string
    {
        // "M_Standing_Expressions_001" → "Standing Expressions 001"
        $without_prefix = preg_replace('/^[MF]_/', '', $stem);
        return str_replace('_', ' ', $without_prefix ?? $stem);
    }
}
