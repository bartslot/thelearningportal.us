<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\LessonStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CatalogManifestRequest;
use App\Http\Resources\LessonManifestResource;
use App\Models\Lesson;
use App\Services\LessonTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * GET /api/v1/catalog/manifest
 *
 * The catalogue as one JSON document: every published lesson with its taxonomy, its CDN image
 * links and a version token. Two jobs, one shape:
 *
 *  - The Flutter app syncs against it. Pass ?since=<ISO8601> to get only what changed, plus the
 *    codes of lessons that went away, so a client can prune without diffing the whole list. The
 *    response carries an ETag, so an unchanged catalogue costs a 304 and no body at all.
 *  - Moving lessons between environments (local → online) reads the same document. That is why
 *    entries are keyed on lesson_code rather than the numeric id, which does not survive the trip.
 *
 * Public and read-only, because the lessons it lists are already public — the player at
 * /lesson/{code} needs no account either. Drafts are never included.
 */
class CatalogManifestController extends Controller
{
    public function __invoke(CatalogManifestRequest $request, LessonTaxonomy $taxonomy): JsonResponse
    {
        $since = $request->since();

        $lessons = $this->lessons($since);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'since' => optional($since)->toIso8601String(),
            'count' => $lessons->count(),
            'lessons' => $lessons
                ->map(fn (Lesson $lesson) => (new LessonManifestResource($lesson, $taxonomy))->toArray($request))
                ->values()
                ->all(),
            // Codes a client should drop: retired, unpublished or deleted since it last asked.
            // Only meaningful with ?since — a first sync has nothing to prune.
            'removed' => $since ? $this->removedCodes($since) : [],
            // The labels behind the taxonomy keys, so a client can build filters without shipping
            // its own copy of the vocabulary and drifting out of step with ours.
            'vocabulary' => $this->vocabulary(),
        ];

        // Sync-friendly: the same catalogue always hashes the same, so a client that sends
        // If-None-Match gets a 304 instead of the list.
        $etag = '"'.substr(hash('sha256', json_encode($payload['lessons']).json_encode($payload['removed'])), 0, 32).'"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->json(null, 304)->setEtag($etag, false);
        }

        return response()->json($payload)->setEtag($etag, false);
    }

    /**
     * Published lessons, newest change first. Scenes are eager-loaded because both the taxonomy
     * facets and the per-lesson version token read them — without it this is a query per lesson.
     *
     * @return Collection<int, Lesson>
     */
    private function lessons(?\Illuminate\Support\Carbon $since): Collection
    {
        return Lesson::query()
            ->where('status', LessonStatus::Published)
            ->whereNotNull('lesson_code')
            ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
            ->with(['scenes', 'firstScene', 'source'])
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Lessons a client may still be holding that no longer belong in the catalogue: deleted, or
     * no longer published. Soft-deleted rows are included deliberately — a client cannot learn
     * about a deletion from a list the row has vanished from.
     *
     * @return list<string>
     */
    private function removedCodes(\Illuminate\Support\Carbon $since): array
    {
        return Lesson::withTrashed()
            ->whereNotNull('lesson_code')
            ->where(fn ($q) => $q->where('updated_at', '>', $since)->orWhere('deleted_at', '>', $since))
            ->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhere('status', '!=', LessonStatus::Published))
            ->pluck('lesson_code')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The taxonomy vocabulary, flattened to key => label. Internal matching details (the keyword
     * lists that classify a region, the year bounds of a period) stay on the server; a client only
     * needs something to print next to a filter.
     *
     * @return array<string, array<string, string>>
     */
    private function vocabulary(): array
    {
        $labels = static fn (string $facet): array => collect(config("lesson-taxonomy.{$facet}", []))
            ->map(fn (array $entry): string => __($entry['label']))
            ->all();

        return [
            'subjects' => $labels('subjects'),
            'periods' => $labels('periods'),
            'regions' => $labels('regions'),
            'formats' => $labels('formats'),
            'interactivity' => $labels('interactivity'),
            'grade_bands' => $labels('grade_bands'),
        ];
    }
}
