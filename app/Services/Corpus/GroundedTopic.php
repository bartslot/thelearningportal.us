<?php

declare(strict_types=1);

namespace App\Services\Corpus;

/**
 * A lesson topic matched against our own catalog — the facts we already hold, before
 * any model is asked anything.
 */
final readonly class GroundedTopic
{
    public function __construct(
        public string $id,
        public string $type,
        public string $name,
        public ?string $qid,
        public ?string $wikipediaUrl,
        public ?string $summary,
        public ?int $eraStart,
        public ?int $eraEnd,
        public ?string $regionLabel,
        public ?string $attribution,
        /** 1.0 for an exact name/alias hit, lower for a fuzzy one. */
        public float $confidence,
    ) {}

    /**
     * The catalog's own words, as a source block the prompt can quote from. Kept short —
     * it is a header above the full article, not a replacement for it.
     */
    public function sourceBlock(): string
    {
        $lines = ["== {$this->name} =="];

        $era = $this->eraLabel();
        if ($era !== null) {
            $lines[] = "Period: {$era}";
        }
        if (filled($this->regionLabel)) {
            $lines[] = "Region: {$this->regionLabel}";
        }
        if (filled($this->summary)) {
            $lines[] = '';
            $lines[] = trim((string) $this->summary);
        }
        if (filled($this->attribution)) {
            $lines[] = '';
            $lines[] = "Source: {$this->attribution}";
        }

        return implode("\n", $lines);
    }

    public function eraLabel(): ?string
    {
        $fmt = fn (int $y): string => $y < 0 ? abs($y).' BCE' : $y.' CE';

        return match (true) {
            $this->eraStart !== null && $this->eraEnd !== null => $fmt($this->eraStart).' – '.$fmt($this->eraEnd),
            $this->eraStart !== null => 'from '.$fmt($this->eraStart),
            $this->eraEnd !== null => 'until '.$fmt($this->eraEnd),
            default => null,
        };
    }
}
