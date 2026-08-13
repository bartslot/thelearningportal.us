<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The hand-authored territories, read from the one file that defines them.
 *
 * The same JSON the map imports (database/data/hand-territories.json). PHP reads it for two jobs
 * the browser cannot do: stamping an incoming teacher report with what we ACTUALLY claimed — never
 * with what the browser said we claimed — and auditing the set against Wikidata.
 *
 * That first job is the reason this is a service rather than a json_decode at the call site. A
 * report whose confidence and chosen source came from the client is a report that can be made to
 * say anything, and its whole value is being a trustworthy record of what a teacher saw.
 */
final class HandTerritories
{
    /** @var array<string, array<string, mixed>>|null id → record */
    private ?array $byId = null;

    public function __construct(private readonly ?string $path = null) {}

    private function file(): string
    {
        return $this->path ?? database_path('data/hand-territories.json');
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->byId !== null) {
            return $this->byId;
        }

        $file = $this->file();
        if (! File::exists($file)) {
            throw new RuntimeException("Hand-authored territories missing at {$file}.");
        }

        $decoded = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
        $this->byId = [];
        foreach ($decoded['territories'] ?? [] as $territory) {
            $this->byId[$territory['id']] = $territory;
        }

        return $this->byId;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return $this->find($id) !== null;
    }

    /**
     * What we claim about a territory, for stamping onto a report.
     *
     * Returns nulls rather than throwing for an unknown id: a report about a Cliopatria territory is
     * perfectly valid and simply has no hand-authored provenance to record.
     *
     * @return array{confidence: string|null, chosen_source: string|null, label: string|null, qid: string|null}
     */
    public function claimFor(string $id): array
    {
        $territory = $this->find($id);

        return [
            'confidence' => $territory['provenance']['confidence'] ?? null,
            'chosen_source' => $territory['provenance']['chosen_source'] ?? null,
            'label' => $territory['name'] ?? null,
            'qid' => $territory['qid'] ?? null,
        ];
    }
}
