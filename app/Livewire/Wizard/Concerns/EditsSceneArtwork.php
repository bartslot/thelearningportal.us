<?php

declare(strict_types=1);

namespace App\Livewire\Wizard\Concerns;

use App\Models\Scene;
use App\Models\SvgAsset;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

trait EditsSceneArtwork
{
    /** Asset id of the layer whose settings fill the inspector (Keynote "active object"). */
    public ?int $activeLayerId = null;

    #[On('svg-asset:attach')]
    public function onSvgAssetAttach(int $assetId): void
    {
        $this->attachArtwork($assetId);
    }

    /** A layer was selected on the canvas / object list → its settings take over the inspector. */
    #[On('layer:selected')]
    public function setActiveLayer(int $assetId): void
    {
        $this->activeLayerId = $assetId;
        $this->panelView = 'scene';   // never on the global Settings tab while editing a layer
    }

    /** Deselected (bg/text selected, empty canvas, or the back button) → inspector returns to scene. */
    #[On('layer:deselected')]
    public function clearActiveLayer(): void
    {
        $this->activeLayerId = null;
    }

    /** The active layer's enriched data (title, url, all fields), or null. */
    #[Computed]
    public function activeLayer(): ?array
    {
        if (! $this->activeLayerId) {
            return null;
        }

        return collect($this->sceneArtworkLayers())->firstWhere('asset_id', $this->activeLayerId);
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
            // Free position on the stage (centre anchor, % of the stage) — teacher drags it
            // on the canvas. Defaults to just-below-centre where a figure usually reads best.
            'x' => 50.0,
            'y' => 58.0,
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

    /**
     * Build a shots array carrying the given background image plus any clipart layers already on
     * the scene, so swapping the background (painting / pasted URL) doesn't silently delete the
     * teacher's manually-placed clipart. Returns null when there is no clipart to preserve — the
     * caller then keeps the simpler flat-background behaviour (shots = null).
     */
    private function shotsPreservingArtwork(Scene $scene, string $newImagePath): ?array
    {
        $assetLayers = collect($scene->shots[0]['layers'] ?? [])
            ->filter(fn ($l) => ($l['asset_id'] ?? null) !== null)
            ->values()
            ->all();

        if ($assetLayers === []) {
            return null;
        }

        $cover = ['path' => $newImagePath, 'kind' => 'cover', 'depth' => (float) 0.4];

        return [[
            'order' => 0,
            'image_path' => $newImagePath,
            'layers' => array_merge([$cover], $assetLayers),
        ]];
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
            'blur' => [0, 2.5],
            'sway' => null, // boolean
            'blend' => ['multiply', 'screen', 'overlay', 'darken', 'lighten'],
            'x' => [0, 100],   // stage position %, centre anchor
            // Drawing-mode ink controls (per layer).
            'ink_preset' => ['production', 'brush', 'etch', 'sketch', 'liner'],
            'ink_fill' => ['auto', 'none', 'wash', 'hatch', 'cross'],
            'draw_time' => [2, 20],   // seconds for the full draw-on
            'y' => [0, 100],
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
            'depth', 'scale', 'opacity', 'blur', 'x', 'y', 'draw_time' => (float) $value,
            'height', 'wobble' => (int) $value,
            'sway' => (bool) $value,
            'kind', 'blend', 'ink_preset', 'ink_fill' => (string) $value,
            default => $value,
        };

        // Apply clamping for numeric fields, then re-cast to ensure type fidelity.
        if ($whitelist[$field] !== null && is_array($whitelist[$field]) && is_numeric($coercedValue)) {
            [$min, $max] = $whitelist[$field];
            // Clamp: use floats for min/max to preserve float results when clamping floats
            $coercedValue = max((float) $min, min((float) $max, (float) $coercedValue));
            // Re-cast after clamping to preserve float/int type
            if (in_array($field, ['depth', 'scale', 'opacity', 'blur', 'x', 'y'], true)) {
                $coercedValue = (float) $coercedValue;
            } elseif (in_array($field, ['height', 'wobble'], true)) {
                $coercedValue = (int) $coercedValue;
            }
        }

        // Validate enum-like fields.
        if (in_array($field, ['kind', 'blend', 'ink_preset', 'ink_fill'], true)) {
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

    /**
     * Batched move + scale from an on-canvas drag/resize. Persists all three in one call and,
     * crucially, does NOT re-dispatch scene:load — the canvas editor already holds the new
     * position, so re-broadcasting would clobber the layer mid-interaction (same guard the
     * text overlay uses). The Livewire re-render still refreshes the Layers panel thumbnails.
     */
    #[On('artwork:move')]
    public function moveArtworkLayer(int $assetId, float $x, float $y, float $scale): void
    {
        if (! $this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $shots = $scene->shots ?? [];
        if (empty($shots)) {
            return;
        }

        $x = max(0.0, min(100.0, $x));
        $y = max(0.0, min(100.0, $y));
        $scale = max(0.2, min(3.0, $scale));

        $shots = collect($shots)->map(function (array $shot) use ($assetId, $x, $y, $scale): array {
            $layers = collect($shot['layers'] ?? [])->map(function (array $l) use ($assetId, $x, $y, $scale): array {
                if (($l['asset_id'] ?? null) === $assetId) {
                    $l['x'] = $x;
                    $l['y'] = $y;
                    $l['scale'] = $scale;
                }

                return $l;
            })->all();

            return array_merge($shot, ['layers' => $layers]);
        })->all();

        $scene->update(['shots' => $shots]);
        unset($this->sceneArtworkLayers);   // recompute the Layers panel; no scene:load re-dispatch
    }

    /**
     * Reorder clipart layers from the object-list drag. `$assetIds` is the new paint order
     * (bottom-first — the overlay draws array order, last on top). `$onTop` records whether the
     * whole clipart group now sits above the text overlay (the canvas host's z-index follows it).
     *
     * Like moveArtworkLayer, this does NOT re-dispatch scene:load — the canvas already applied the
     * new order/z client-side, so re-broadcasting would clobber the just-dropped state.
     */
    #[On('artwork:reorder')]
    public function reorderArtworkLayers(array $assetIds, bool $onTop): void
    {
        if (! $this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $shots = $scene->shots ?? [];
        if (empty($shots)) {
            return;
        }

        // asset_id => desired position (lower = painted earlier = visually lower).
        $rank = array_flip(array_values(array_map('intval', $assetIds)));

        $shots = collect($shots)->map(function (array $shot) use ($rank): array {
            $layers = $shot['layers'] ?? [];
            // Non-asset layers (the base cover image) stay pinned at the bottom of the stack.
            $covers = array_values(array_filter($layers, fn ($l) => ($l['asset_id'] ?? null) === null));
            $assets = array_values(array_filter($layers, fn ($l) => ($l['asset_id'] ?? null) !== null));
            usort($assets, fn ($a, $b) => ($rank[$a['asset_id']] ?? PHP_INT_MAX) <=> ($rank[$b['asset_id']] ?? PHP_INT_MAX));

            return array_merge($shot, ['layers' => array_merge($covers, $assets)]);
        })->all();

        $config = $scene->config ?? [];
        $config['clipart_on_top'] = $onTop;

        $scene->update(['shots' => $shots, 'config' => $config]);

        // Mirror the config change back into the in-memory snapshot. Without this, the next
        // saveSelected() (e.g. blurring Year/Location) would write the STALE snapshot config
        // over the row and silently revert clipart_on_top — 'config' is an EDITABLE_FIELD.
        if ($this->selectedSceneId === $scene->id && $this->selectedScene !== null) {
            $this->selectedScene['config'] = $scene->config;
        }

        unset($this->sceneArtworkLayers);   // refresh the Layers panel; no scene:load re-dispatch
    }

    /**
     * Slideshow render mode (stored in config['slideshow_mode']):
     *   'standard' — flat image, no depth.
     *   'parallax' — layers (and text) follow the camera by depth; per-layer Depth control shows.
     *   'drawing'  — ink line-art draw-on animation (pen engine).
     * Read back in the scene:load payload for the canvas.
     */
    public function setSlideshowMode(string $mode): void
    {
        if (! in_array($mode, ['standard', 'parallax', 'drawing'], true)) {
            return;
        }
        if (! $this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $config = $scene->config ?? [];
        $config['slideshow_mode'] = $mode;
        $config['parallax'] = $mode === 'parallax';   // back-compat for readers still checking the bool
        $scene->update(['config' => $config]);

        if ($this->selectedScene !== null) {
            $this->selectedScene['config'] = $config;
        }
        $this->selectSceneInternal($scene->id);
    }

    /** @return array<int, array{asset_id: int, path: string, title: string, url: string, depth: float, scale: float, height: float, sway: bool, x?: float, y?: float, wobble?: int, opacity?: float, blur?: float, z?: int, blend?: string, kind?: string}> */
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
