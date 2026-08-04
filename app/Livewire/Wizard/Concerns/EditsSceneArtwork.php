<?php

declare(strict_types=1);

namespace App\Livewire\Wizard\Concerns;

use App\Models\Scene;
use App\Models\SvgAsset;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

trait EditsSceneArtwork
{
    /** Asset id of the layer whose settings fill the inspector (Keynote "active object"). */
    public ?int $activeLayerId = null;

    /**
     * An icon was picked in the Icons panel, or an SVG in the teacher's own library.
     *
     * A click carries no position and lands mid-stage; a drag carries the drop point, and over a
     * live map it also carries the place under the cursor so the icon is pinned there.
     */
    #[On('svg-asset:attach')]
    public function onSvgAssetAttach(int $assetId, ?float $x = null, ?float $y = null, ?float $lng = null, ?float $lat = null): void
    {
        $this->attachArtwork($assetId, $x, $y, $lng, $lat);
    }

    /** A layer was selected on the canvas / object list → its settings take over the inspector. */
    #[On('layer:selected')]
    public function setActiveLayer(int $assetId): void
    {
        $this->activeLayerId = $assetId;
        $this->activeTextId = null;
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

    /**
     * @param  float|null  $x  drop point on the stage (% , centre anchor); null = the default spot
     * @param  float|null  $lng  the place under the drop point when a map is live beneath the stage
     */
    public function attachArtwork(int $assetId, ?float $x = null, ?float $y = null, ?float $lng = null, ?float $lat = null): void
    {
        if (! $this->selectedSceneId) {
            $this->dispatch('toast', message: 'No scene selected.', type: 'warning');

            return;
        }

        // The teacher's own imports AND the icons that ship with the app — an icon belongs to
        // nobody, so ownedBy() would refuse every one of them.
        $asset = SvgAsset::query()
            ->availableTo((int) auth()->id())
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
            'x' => $x !== null ? max(0.0, min(100.0, $x)) : 50.0,
            'y' => $y !== null ? max(0.0, min(100.0, $y)) : 58.0,
        ];

        // Dropped over a live map → the icon belongs to that PLACE, not to that pixel, so it
        // keeps sitting on it through every pan and zoom. Same anchor a text label uses.
        if ($lng !== null && $lat !== null) {
            $newLayer['anchor'] = 'map';
            $newLayer['lng'] = max(-180.0, min(180.0, $lng));
            $newLayer['lat'] = max(-90.0, min(90.0, $lat));
        }

        // Immutable transformation: build new arrays, never mutate in place
        if (empty($shots)) {
            // Scenes that draw their own backdrop (map, voyage, panorama) have no flat background
            // image — the MAP or the sphere IS the backdrop. Still allow a layer on top: create a
            // shot carrying ONLY the asset layer (no cover). Editor and player both render these
            // over the map; serializeShots keeps layer-only shots.
            if (! $scene->image_path) {
                if ($scene->drawsOwnBackdrop()) {
                    $shots = [[
                        'order' => 0,
                        'layers' => [$newLayer],
                    ]];
                    $scene->update(['shots' => $shots]);
                    $this->selectSceneInternal($scene->id);
                    $this->svgLibraryOpen = false;

                    return;
                }

                $this->dispatch('toast', message: __('Generate a scene background first, then add an image on top of it.'), type: 'warning');

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
                if (empty($layers) && ! empty($shot['image_path']) && empty($shot['bg_path'])) {
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
     * Add a 3D (Sketchfab) or video (YouTube/Vimeo/embed) as a free-positioned LAYER on top of the
     * scene — the sibling of a clipart layer, but its node is an <iframe> instead of an <img>. Parsed
     * by the same EmbedParser as the backgrounds; stored in shots[].layers with kind='embed' and a
     * synthetic asset_id so the existing move / reorder / delete plumbing (all keyed on asset_id) works.
     *
     * $kind: '3d' (Sketchfab) or 'video' (YouTube / Vimeo / pasted iframe).
     */
    public function addEmbedLayer(string $input, string $kind): void
    {
        if (! $this->selectedSceneId) {
            $this->dispatch('toast', message: __('No scene selected.'), type: 'warning');

            return;
        }

        $parser = app(\App\Services\EmbedParser::class);
        if ($kind === '3d') {
            $p = $parser->sketchfab($input);
            if (! $p) {
                $this->dispatch('toast', message: __('That doesn\'t look like a Sketchfab model link.'), type: 'warning');

                return;
            }
            // Arrives as the teacher would want it: the studio backdrop gone, turning gently, and
            // able to be grabbed and spun. Every one of those is a switch in the layer inspector.
            $opts = ['interact' => true, 'autospin' => true, 'bg' => 'none'];
            $embed = [
                'type' => 'sketchfab',
                'title' => __('3D model'),
                'model_id' => $p['id'],   // kept so the src can be rebuilt when an option changes
                'opts' => $opts,
                'src' => $parser->sketchfabSrc($p['id'], $opts),
            ];
        } else {
            $p = $parser->video($input);
            if (! $p) {
                $this->dispatch('toast', message: __('Paste a YouTube, Vimeo or embed link.'), type: 'warning');

                return;
            }
            // A video layer autoplays muted with no controls, and letterboxes (contain) so nothing crops.
            $opts = ['autoplay' => true, 'controls' => false, 'start' => 0, 'end' => 0];
            $embed = [
                'type' => 'video',
                'title' => __('Video'),
                'provider' => $p['provider'] ?? 'other',
                'embed_base' => $p['src'],
                'src' => $parser->embedVideoSrc($p, $opts),
            ];
        }

        $newLayer = [
            'asset_id' => random_int(1_000_000_001, 1_999_999_999),   // synthetic id (no SvgAsset row)
            'kind' => 'embed',
            'embed' => $embed,
            'depth' => 1.0,
            'scale' => 1.0,
            'height' => 45,
            'x' => 50.0,
            'y' => 50.0,
        ];

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $shots = $scene->shots ?? [];

        if (empty($shots)) {
            if ($scene->image_path) {
                $shots = [[
                    'order' => 0,
                    'image_path' => $scene->image_path,
                    'layers' => [
                        ['path' => $scene->image_path, 'kind' => 'cover', 'depth' => 0.4],
                        $newLayer,
                    ],
                ]];
            } else {
                // Map-backed (voyage) scene — layer-only shot, the map is the backdrop.
                $shots = [['order' => 0, 'layers' => [$newLayer]]];
            }
        } else {
            $layers = $shots[0]['layers'] ?? [];
            if (empty($layers) && ! empty($shots[0]['image_path'])) {
                $layers[] = ['path' => $shots[0]['image_path'], 'kind' => 'cover', 'depth' => 0.4];
            }
            $layers[] = $newLayer;
            $shots[0] = array_merge($shots[0], ['layers' => $layers]);
        }

        $scene->update(['shots' => $shots]);
        $this->selectSceneInternal($scene->id);
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
        if (! $this->selectedSceneId) {
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
        })
            // A map-backed scene's shot is layer-only (no image_path). Once its last clipart is
            // removed it carries nothing — drop the empty shot so shots collapses back to [].
            ->reject(fn (array $shot) => empty($shot['image_path']) && empty($shot['layers'] ?? []))
            ->values()
            ->all();

        $scene->update(['shots' => $shots]);
        $this->selectSceneInternal($scene->id);
    }

    public function updateArtworkLayer(int $assetId, string $field, mixed $value): void
    {
        // Whitelist allowed fields with clamps.
        $whitelist = [
            'depth' => [0, 3],
            'scale' => [0.2, 6],   // 6x: big enough to fill the stage with one detail
            'height' => [5, 100],
            'wobble' => [0, 2],
            'opacity' => [0.05, 1],
            'kind' => ['figure', 'strip', 'cover'],
            'blur' => [0, 2.5],
            'sway' => null, // boolean
            // 'normal' is how a teacher turns blending back OFF — without it in the list the
            // enum check below rejects the value and the select silently refuses to clear.
            'blend' => ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten'],
            // Colour treatment. white_key is how much of the top of the luminance range becomes
            // transparent — 0.04 is the 4% that clears scanned paper without eating light greys.
            'white_key' => [0, 0.5],
            // Build In / Build Out. Duration is the industry term (Keynote, After Effects, CSS),
            // and it is milliseconds here to match WAAPI rather than converting at every call.
            'anim_duration' => [100, 5000],
            'anim_out' => ['none', 'fade', 'slide-left', 'slide-right', 'slide-up', 'slide-down', 'zoom', 'pop'],
            'anim_out_delay' => [0, 10],
            'anim_out_ease' => ['enter', 'move', 'exit', 'pop', 'linear'],
            'anim_out_duration' => [100, 5000],
            'grayscale' => null,   // boolean
            'tint' => null,        // #rrggbb or '' to clear
            // Animate tab: how the layer arrives, how long it waits first, and on which curve.
            // The vocabulary is shared with resources/js/scene/animations.js, which plays it.
            'anim' => ['none', 'fade', 'slide-left', 'slide-right', 'slide-up', 'slide-down', 'zoom', 'pop'],
            'anim_delay' => [0, 10],   // seconds before the entrance starts
            'anim_ease' => ['enter', 'move', 'exit', 'pop', 'linear'],
            'x' => [0, 100],   // stage position %, centre anchor
            // Drawing-mode ink controls (per layer).
            'ink_preset' => ['production', 'brush', 'etch', 'sketch', 'liner'],
            'ink_fill' => ['auto', 'none', 'wash', 'hatch', 'cross'],
            'draw_time' => [2, 20],   // seconds for the full draw-on
            'y' => [0, 100],
        ];

        if (! array_key_exists($field, $whitelist)) {
            return; // Unknown field, silently reject.
        }

        if (! $this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $shots = $scene->shots ?? [];

        if (empty($shots)) {
            return;
        }

        // A tint is free-form colour, so it is validated here rather than against a list: either
        // "leave the artwork alone" or a hex colour. Anything else is dropped.
        // Coerce and clamp the value.
        $coercedValue = match ($field) {
            'depth', 'scale', 'opacity', 'blur', 'x', 'y', 'draw_time', 'anim_delay' => (float) $value,
            'height', 'wobble' => (int) $value,
            'sway', 'grayscale' => (bool) $value,
            'kind', 'blend', 'ink_preset', 'ink_fill', 'anim', 'anim_ease' => (string) $value,
            default => $value,
        };

        // Apply clamping for numeric fields, then re-cast to ensure type fidelity.
        if ($whitelist[$field] !== null && is_array($whitelist[$field]) && is_numeric($coercedValue)) {
            [$min, $max] = $whitelist[$field];
            // Clamp: use floats for min/max to preserve float results when clamping floats
            $coercedValue = max((float) $min, min((float) $max, (float) $coercedValue));
            // Re-cast after clamping to preserve float/int type
            if (in_array($field, ['depth', 'scale', 'opacity', 'blur', 'x', 'y', 'anim_delay'], true)) {
                $coercedValue = (float) $coercedValue;
            } elseif (in_array($field, ['height', 'wobble'], true)) {
                $coercedValue = (int) $coercedValue;
            }
        }

        // A tint is a hex colour or empty (cleared). Anything else is dropped rather than stored,
        // because it would end up inside an SVG flood-color attribute.
        if ($field === 'tint') {
            $coercedValue = trim((string) $coercedValue);
            if ($coercedValue !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', $coercedValue)) {
                return;
            }
            if ($coercedValue === '') {
                $coercedValue = null;
            }
        }

        // Validate enum-like fields.
        if (in_array($field, ['kind', 'blend', 'ink_preset', 'ink_fill', 'anim', 'anim_ease'], true)) {
            if (! in_array($coercedValue, $whitelist[$field], true)) {
                return;
            }
        }

        $this->writeLayerField($assetId, $field, $coercedValue);
    }

    /**
     * Write one already-validated field onto the matching layer in every shot, immutably.
     *
     * The last step of every per-layer setting, shared so a new control cannot quietly grow its
     * own slightly-different way of saving.
     */
    private function writeLayerField(int $assetId, string $field, mixed $value): void
    {
        if (! $this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $shots = $scene->shots ?? [];
        if (empty($shots)) {
            return;
        }

        $shots = collect($shots)->map(function (array $shot) use ($assetId, $field, $value): array {
            $layers = collect($shot['layers'] ?? [])->map(function (array $l) use ($assetId, $field, $value): array {
                if (($l['asset_id'] ?? null) === $assetId) {
                    $l[$field] = $value;
                }

                return $l;
            })->all();

            return array_merge($shot, ['layers' => $layers]);
        })->all();

        $scene->update(['shots' => $shots]);
        $this->selectSceneInternal($scene->id);
    }

    /**
     * How a placed 3D model behaves: can the class grab and spin it, does it turn by itself, and
     * what sits behind it.
     *
     * The viewer URL is rebuilt from the stored model id rather than string-patched, so the
     * parameters can never drift out of step with each other.
     *
     * @param  string  $option  interact | autospin | bg
     * @param  mixed  $value  bool for the switches; 'none' | 'glass' | '#rrggbb' for bg
     */
    public function setEmbedOption(int $assetId, string $option, mixed $value): void
    {
        if (! in_array($option, ['interact', 'autospin', 'bg'], true) || ! $this->selectedSceneId) {
            return;
        }
        $coerced = $option === 'bg'
            ? (in_array($value, ['none', 'glass'], true) || preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value)
                ? (string) $value
                : 'none')
            : filter_var($value, FILTER_VALIDATE_BOOLEAN);

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $parser = app(\App\Services\EmbedParser::class);

        $shots = collect($scene->shots ?? [])->map(function (array $shot) use ($assetId, $option, $coerced, $parser): array {
            $layers = collect($shot['layers'] ?? [])->map(function (array $l) use ($assetId, $option, $coerced, $parser): array {
                if (($l['asset_id'] ?? null) !== $assetId || ($l['embed']['type'] ?? null) !== 'sketchfab') {
                    return $l;
                }
                $opts = array_merge(['interact' => true, 'autospin' => true, 'bg' => 'none'], $l['embed']['opts'] ?? []);
                $opts[$option] = $coerced;
                // Older layers were stored before the model id was kept; recover it from the src.
                $id = $l['embed']['model_id'] ?? (preg_match('/([0-9a-f]{32})/i', (string) ($l['embed']['src'] ?? ''), $m) ? strtolower($m[1]) : null);
                if ($id === null) {
                    return $l;
                }
                $l['embed'] = array_merge($l['embed'], [
                    'model_id' => $id,
                    'opts' => $opts,
                    'src' => $parser->sketchfabSrc($id, $opts),
                ]);

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
    public function moveArtworkLayer(int $assetId, float $x, float $y, float $scale, ?string $anchor = null, ?float $lng = null, ?float $lat = null): void
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
        $scale = max(0.2, min(6.0, $scale));   // keep in step with the Size slider + MAX_SCALE

        // A pinned layer's PLACE is what has to survive; x/y is only where the current camera
        // puts it. A pin is only accepted with real coordinates, so a projector that failed to
        // resolve one can never leave a layer anchored to nowhere.
        $pinned = $anchor === 'map' && $lng !== null && $lat !== null;
        $lng = $pinned ? max(-180.0, min(180.0, $lng)) : null;
        $lat = $pinned ? max(-90.0, min(90.0, $lat)) : null;

        $shots = collect($shots)->map(function (array $shot) use ($assetId, $x, $y, $scale, $anchor, $pinned, $lng, $lat): array {
            $layers = collect($shot['layers'] ?? [])->map(function (array $l) use ($assetId, $x, $y, $scale, $anchor, $pinned, $lng, $lat): array {
                if (($l['asset_id'] ?? null) === $assetId) {
                    $l['x'] = $x;
                    $l['y'] = $y;
                    $l['scale'] = $scale;
                    // Only an overlay that knows about anchoring sends one; an older caller
                    // that doesn't leaves whatever the layer already had alone.
                    if ($anchor !== null) {
                        if ($pinned) {
                            $l['anchor'] = 'map';
                            $l['lng'] = $lng;
                            $l['lat'] = $lat;
                        } else {
                            unset($l['anchor'], $l['lng'], $l['lat']);
                        }
                    }
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
    /**
     * How the background image fills the stage: 'cover' (fill the frame, crop the overflow) or
     * 'contain' (show the whole work, letterboxed).
     *
     * A cover fit anchors portraits to the top automatically — the renderers measure the image, so
     * there is no third "focus" control for the teacher to get wrong. See scene/background-fit.js.
     */
    public function setBackgroundFit(string $fit): void
    {
        if (! in_array($fit, ['cover', 'contain'], true)) {
            return;
        }
        if (! $this->selectedSceneId) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($this->selectedSceneId);
        $config = $scene->config ?? [];
        $config['background_fit'] = $fit;
        $scene->update(['config' => $config]);

        // Keep the snapshot in step, or saveSelected() would write the pre-edit config back.
        if ($this->selectedScene !== null) {
            $this->selectedScene['config'] = $config;
        }
        $this->selectSceneInternal($scene->id);
    }

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
                if (! $asset) {
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
