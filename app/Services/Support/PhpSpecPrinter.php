<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Render a spec array as readable PHP source.
 *
 * `var_export` would work, but it writes `array (` blocks and quotes integer keys, and the point of
 * resources/lessons/*.php is that a human keeps editing the file after it is generated. This prints
 * short-array syntax, keeps small scalar lists on one line, and otherwise puts one key per line.
 */
final class PhpSpecPrinter
{
    /** A list of scalars shorter than this stays on a single line. */
    private const INLINE_WIDTH = 96;

    private const INDENT = '    ';

    public function print(mixed $value, int $depth = 0): string
    {
        if (is_array($value)) {
            return $this->printArray($value, $depth);
        }

        return $this->scalar($value);
    }

    /** @param array<mixed> $value */
    private function printArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);

        if ($isList && $this->allScalars($value)) {
            $inline = '['.implode(', ', array_map($this->scalar(...), $value)).']';
            if (mb_strlen($inline) + strlen(self::INDENT) * $depth <= self::INLINE_WIDTH) {
                return $inline;
            }
        }

        $pad = str_repeat(self::INDENT, $depth + 1);
        $out = "[\n";
        foreach ($value as $key => $item) {
            $out .= $pad;
            if (! $isList) {
                $out .= $this->scalar((string) $key).' => ';
            }
            $out .= $this->print($item, $depth + 1).",\n";
        }

        return $out.str_repeat(self::INDENT, $depth).']';
    }

    /** @param array<mixed> $value */
    private function allScalars(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                return false;
            }
        }

        return true;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->float($value),
            default => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'",
        };
    }

    /** Coordinates are the floats that matter here, and 4dp is ~11 metres. */
    private function float(float $value): string
    {
        $out = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');

        return $out === '' || $out === '-' ? '0.0' : $out;
    }
}
