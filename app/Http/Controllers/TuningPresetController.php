<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Named presets for the dev settings panel.
 *
 * The point of writing to a file under resources/ rather than to a database or localStorage: a look
 * that has been tuned IS a change to the app, and it should arrive the way every other change does
 * — visible in `git diff`, reviewable, revertable. A value hidden in a browser's storage is a value
 * that exists on one machine and is lost the first time someone clears it.
 *
 * LOCAL ONLY. This writes to the source tree, so it must never be reachable anywhere else; the
 * route is registered only when the app is local, and this guards again in case that ever slips.
 */
final class TuningPresetController extends Controller
{
    /** Presets can hold a lot of numbers, but not a payload. */
    private const MAX_BYTES = 256 * 1024;

    private const MAX_PRESETS = 40;

    private function path(): string
    {
        return resource_path('js/dev/tuning.json');
    }

    private function guard(): void
    {
        abort_unless(app()->environment('local'), 404);
    }

    private function read(): array
    {
        if (! File::exists($this->path())) {
            return ['active' => null, 'presets' => []];
        }

        $data = json_decode(File::get($this->path()), true);

        return is_array($data) ? $data + ['active' => null, 'presets' => []] : ['active' => null, 'presets' => []];
    }

    public function index(): JsonResponse
    {
        $this->guard();

        return response()->json($this->read());
    }

    public function store(Request $request): JsonResponse
    {
        $this->guard();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'values' => ['required', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $name = trim($validated['name']);
        abort_if($name === '', 422, 'A preset needs a name.');

        // Only scalars, only two levels deep: group -> control -> number|string|bool. Anything else
        // is not something a control produced, so it has no business being written to the tree.
        $values = [];
        foreach ($validated['values'] as $group => $controls) {
            if (! is_string($group) || ! is_array($controls)) {
                continue;
            }
            foreach ($controls as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $values[$group][$key] = $value;
                }
            }
        }

        $data = $this->read();
        $isNew = ! isset($data['presets'][$name]);
        abort_if($isNew && count($data['presets']) >= self::MAX_PRESETS, 422, 'Too many presets — delete some first.');

        $data['presets'][$name] = $values;
        if ($validated['active'] ?? true) {
            $data['active'] = $name;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        abort_if(strlen($json) > self::MAX_BYTES, 422, 'Preset file would be too large.');

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), $json."\n");

        return response()->json(['saved' => $name, 'presets' => array_keys($data['presets'])]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->guard();

        $name = (string) $request->validate(['name' => ['required', 'string']])['name'];
        $data = $this->read();
        unset($data['presets'][$name]);
        if (($data['active'] ?? null) === $name) {
            $data['active'] = array_key_first($data['presets']);
        }

        File::put($this->path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return response()->json(['deleted' => $name, 'presets' => array_keys($data['presets'])]);
    }
}
