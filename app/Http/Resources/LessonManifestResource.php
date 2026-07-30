<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Lesson;
use App\Services\LessonTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One lesson as the sync manifest describes it.
 *
 * This is a CATALOGUE entry, not the lesson itself: enough to list, filter and decide whether to
 * (re)download, and nothing more. The playable content still comes from the player URL, so this
 * stays small enough that a class set of a few hundred lessons is one cheap request.
 *
 * @mixin Lesson
 */
class LessonManifestResource extends JsonResource
{
    public function __construct(Lesson $lesson, private readonly LessonTaxonomy $taxonomy)
    {
        parent::__construct($lesson);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // The lesson_code is the identifier a client should key on: it is what a student
            // types, it is in the play URL, and unlike the numeric id it survives a migration
            // between environments — which is the other job this manifest does.
            'code' => $this->lesson_code,
            'id' => $this->id,
            'version' => $this->contentVersion(),

            'title' => $this->title ?: $this->topic,
            'topic' => $this->topic,
            'summary' => $this->details,
            'narrator' => $this->historical_figure,

            'status' => $this->status->value,
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,

            'play_url' => $this->lesson_code ? route('lesson.play', ['lessonCode' => $this->lesson_code]) : null,

            // Every image the catalogue needs, already on the CDN where one exists.
            'media' => [
                'cover' => $this->cardImageUrl(),
            ],

            // config/lesson-taxonomy.php keys — see the manifest's `vocabulary` block for labels.
            'taxonomy' => $this->taxonomy->facets($this->resource),

            'counts' => [
                'scenes' => $this->relationLoaded('scenes') ? $this->scenes->count() : $this->scenes()->count(),
                'quiz_questions' => (int) $this->quiz_question_count,
            ],
        ];
    }

    /**
     * A token that changes whenever anything a client would re-download changes.
     *
     * A fingerprint of the CONTENT, not of the timestamps. Timestamps looked like the obvious
     * answer and are not: `updated_at` has one-second resolution, so two edits inside the same
     * second are indistinguishable, and the lesson row is not touched at all when only a scene
     * changes. Hashing what the client actually renders means the token moves if and only if what
     * it would download has moved.
     */
    private function contentVersion(): string
    {
        $scenes = $this->relationLoaded('scenes') ? $this->scenes : $this->scenes()->get();

        $parts = [
            $this->title, $this->topic, $this->details, $this->historical_figure,
            $this->status->value, (string) $this->duration_seconds,
            (string) $this->quiz_question_count, (string) $this->cardImageUrl(),
        ];

        foreach ($scenes->sortBy('id') as $scene) {
            $parts[] = implode(':', [
                $scene->id, $scene->order, $scene->kind,
                $scene->location, $scene->year, $scene->script_segment,
            ]);
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }
}
