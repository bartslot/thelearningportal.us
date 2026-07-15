<?php

declare(strict_types=1);

namespace App\Livewire\Wizard\Concerns;

use App\Models\SvgAsset;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

trait EditsSceneArtwork
{
    #[On('svg-asset:attach')]
    public function onSvgAssetAttach(int $assetId): void
    {
        $this->attachArtwork($assetId);
    }

    public function attachArtwork(int $assetId): void
    {
        if (!$this->selectedSceneId) {
            $this->dispatch('toast', message: 'No scene selected.', type: 'warning');

            return;
        }

        $asset = SvgAsset::query()
            ->ownedBy((int) auth()->id())
            ->findOrFail($assetId);

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);

        $shots = $scene->shots ?? [];
        $newLayer = [
            'asset_id' => $asset->id,
            'path' => $asset->svg_path,
            'kind' => 'figure',
            'depth' => (float) 1.3,
            'scale' => (float) 1.0,
            'height' => (int) 40,
            'sway' => false,
        ];

        // Immutable transformation: build new arrays, never mutate in place
        if (empty($shots)) {
            // No shots exist. Create scene needs an image_path to generate shots.
            if (!$scene->image_path) {
                $this->dispatch('toast', message: 'Generate a scene image first.', type: 'warning');

                return;
            }

            // Create a single shot with both the base cover layer and the asset layer.
            $coverLayer = [
                'path' => $scene->image_path,
                'kind' => 'cover',
                'depth' => (float) 0.4,
            ];

            $shots = [[
                'order' => 0,
                'image_path' => $scene->image_path,
                'layers' => [$coverLayer, $newLayer],
            ]];
        } else {
            // Shots exist. Append layers to each, preserving order with foreach.
            $updatedShots = [];
            foreach ($shots as $shot) {
                // Check if this asset is already attached to avoid duplicates.
                $existing = collect($shot['layers'] ?? [])->firstWhere('asset_id', $newLayer['asset_id']);
                if ($existing) {
                    $updatedShots[] = $shot; // Already attached, skip.
                    continue;
                }

                $layers = $shot['layers'] ?? [];

                // If no layers but there's an image_path and no bg_path, prepend a cover layer.
                if (empty($layers) && !empty($shot['image_path']) && empty($shot['bg_path'])) {
                    $layers[] = [
                        'path' => $shot['image_path'],
                        'kind' => 'cover',
                        'depth' => (float) 0.4,
                    ];
                }

                // Append the new asset layer.
                $layers[] = $newLayer;

                $updatedShots[] = array_merge($shot, ['layers' => $layers]);
            }
            $shots = $updatedShots;
        }

        $scene->update(['shots' => $shots]);
        $this->selectSceneInternal($scene->id);
        $this->svgLibraryOpen = false;
    }

    public function detachArtwork(int $assetId): void
    {
        if (!$this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $shots = $scene->shots ?? [];

        if (empty($shots)) {
            return;
        }

        // Immutable: remove layers with this asset_id and drop layers key if only cover remains.
        $shots = collect($shots)->map(function (array $shot) use ($assetId): array {
            $layers = collect($shot['layers'] ?? [])->reject(fn ($l) => ($l['asset_id'] ?? null) === $assetId)->values()->all();

            // If only a cover layer remains (no asset_id), drop the layers key entirely.
            if (count($layers) === 1 && ($layers[0]['asset_id'] ?? null) === null) {
                $shot = array_diff_key($shot, array_flip(['layers']));

                return $shot;
            }

            // If layers is empty, drop it.
            if (empty($layers)) {
                $shot = array_diff_key($shot, array_flip(['layers']));

                return $shot;
            }

            return array_merge($shot, ['layers' => $layers]);
        })->all();

        $scene->update(['shots' => $shots]);
        $this->selectSceneInternal($scene->id);
    }

    public function updateArtworkLayer(int $assetId, string $field, mixed $value): void
    {
        // Whitelist allowed fields with clamps.
        $whitelist = [
            'depth' => [0, 3],
            'scale' => [0.2, 3],
            'height' => [5, 100],
            'wobble' => [0, 2],
            'opacity' => [0.05, 1],
            'kind' => ['figure', 'strip', 'cover'],
            'blur' => [0, 50],
            'sway' => null, // boolean
            'blend' => ['multiply', 'screen', 'overlay', 'darken', 'lighten'],
        ];

        if (!array_key_exists($field, $whitelist)) {
            return; // Unknown field, silently reject.
        }

        if (!$this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $shots = $scene->shots ?? [];

        if (empty($shots)) {
            return;
        }

        // Coerce and clamp the value.
        $coercedValue = match ($field) {
            'depth', 'scale', 'opacity', 'blur' => (float) $value,
            'height', 'wobble' => (int) $value,
            'sway' => (bool) $value,
            'kind', 'blend' => (string) $value,
            default => $value,
        };

        // Apply clamping for numeric fields, then re-cast to ensure type fidelity.
        if ($whitelist[$field] !== null && is_array($whitelist[$field]) && is_numeric($coercedValue)) {
            [$min, $max] = $whitelist[$field];
            // Clamp: use floats for min/max to preserve float results when clamping floats
            $coercedValue = max((float) $min, min((float) $max, (float) $coercedValue));
            // Re-cast after clamping to preserve float/int type
            if (in_array($field, ['depth', 'scale', 'opacity', 'blur'], true)) {
                $coercedValue = (float) $coercedValue;
            } elseif (in_array($field, ['height', 'wobble'], true)) {
                $coercedValue = (int) $coercedValue;
            }
        }

        // Validate enum-like fields.
        if (in_array($field, ['kind', 'blend'], true)) {
            if (!in_array($coercedValue, $whitelist[$field], true)) {
                return;
            }
        }

        // Immutable: update the matching layer in every shot.
        $shots = collect($shots)->map(function (array $shot) use ($assetId, $field, $coercedValue): array {
            $layers = collect($shot['layers'] ?? [])->map(function (array $l) use ($assetId, $field, $coercedValue): array {
                if (($l['asset_id'] ?? null) === $assetId) {
                    $l[$field] = $coercedValue;
                }

                return $l;
            })->all();

            return array_merge($shot, ['layers' => $layers]);
        })->all();

        $scene->update(['shots' => $shots]);
        $this->selectSceneInternal($scene->id);
    }

    /** @return array<int, array{asset_id: int, path: string, title: string, url: string, depth: float, scale: float, height: float, sway: bool, wobble?: int, opacity?: float, blur?: float, z?: int, blend?: string, kind?: string}> */
    #[Computed]
    public function sceneArtworkLayers(): array
    {
        // Read the scene fresh — the selectedScene snapshot deliberately excludes
        // the shots payload (see Step3SceneConfigurator::snapshot()).
        if (! $this->selectedSceneId) {
            return [];
        }

        $scene = $this->lesson->scenes()->find($this->selectedSceneId);
        $shots = $scene?->shots ?? [];
        if (empty($shots)) {
            return [];
        }

        $firstShot = $shots[0];
        $layers = $firstShot['layers'] ?? [];

        if (empty($layers)) {
            return [];
        }

        // Collect asset_ids and fetch the assets in one query.
        $assetIds = collect($layers)
            ->map(fn ($l) => $l['asset_id'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($assetIds)) {
            return [];
        }

        $assets = SvgAsset::whereIn('id', $assetIds)->get()->keyBy('id');

        // Build enriched layer array.
        return collect($layers)
            ->filter(fn ($l) => ($l['asset_id'] ?? null) !== null)
            ->map(function (array $l) use ($assets) {
                $asset = $assets->get($l['asset_id'] ?? null);
                if (!$asset) {
                    return null;
                }

                return array_merge($l, [
                    'title' => $asset->title,
                    'url' => $asset->url(),
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }
}
