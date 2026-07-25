<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Builds (or refreshes) a voyage lesson from the shared voyage catalog + an optional tour pack —
 * the reusable core behind BOTH the artisan command (`lessons:make-voyage`) and the wizard's
 * "Voyage" lesson type, so a voyage lesson is no longer hard-wired to Abel Tasman.
 *
 * A voyage lesson is: a Lesson with game_type='voyage' + game_config.voyage=<id>, and one
 * kind='voyage' scene per catalog leg, each carrying its leg index + an inline gallery
 * (title/date/story/images). No generation pipeline — every scene is 'ready'.
 *
 * Data sources:
 *  - resources/js/timemap/voyages.json  → the catalog entry (MUST have `legs`; needs `fleet`).
 *  - resources/js/timemap/<id>-tour.json → optional per-leg `stop` copy (place/date/title/story/images).
 * Remote (http) gallery images are hosted on Cloudinary automatically; local names resolve to
 * /lessons/<voyageId>/<name>.
 */
class VoyageLessonBuilder
{
    /** Map-pinned landfall thumbnails: at most this many of a leg's gallery images. */
    private const MAX_PINS = 3;

    public function __construct(private CloudinaryService $cloud) {}

    /**
     * @param  array{title?:string,topic?:string,grade_level?:string,tone?:string}  $overrides
     */
    public function build(string $voyageId, User $teacher, array $overrides = []): Lesson
    {
        $catalog = $this->catalogEntry($voyageId);
        if (empty($catalog['legs'])) {
            throw new RuntimeException("Voyage '{$voyageId}' has no legs in voyages.json — add legs (and a fleet) before it can become a lesson.");
        }
        $pack = $this->tourPack($voyageId);

        $title = $overrides['title'] ?? ($pack['title'] ?? ($catalog['name'] ?? $voyageId).' — voyage');

        $lesson = Lesson::firstOrCreate(
            ['title' => $title, 'teacher_id' => $teacher->id],
            [
                'topic' => $overrides['topic'] ?? $title,
                'subject' => 'history',
                'grade_level' => $overrides['grade_level'] ?? 'K-7',
                'tone' => $overrides['tone'] ?? 'adventurous',
                'status' => LessonStatus::Previewable->value,
                'include_game' => false,
            ]
        );

        // Seed the lesson's EDITABLE fog from the catalog's `unknown` default. It is no longer baked
        // into the map at render time — copying it here means the teacher can reshape or delete every
        // masked region from the wizard (paint / erase / undo / reset) instead of fighting a constant.
        $gameConfig = ['voyage' => $voyageId];
        if (! empty($catalog['unknown']) && is_array($catalog['unknown'])) {
            $gameConfig['voyage_fog'] = array_values($catalog['unknown']);
        }

        $lesson->update([
            'game_type' => 'voyage',
            'game_config' => $gameConfig,
            'status' => LessonStatus::Previewable->value,
        ]);

        // The builder owns this lesson's structure — rebuild scenes from scratch on every run.
        $lesson->scenes()->delete();

        $order = 1;

        // A "real start" context slide before the sailing (why the voyage happened) — a standalone
        // gallery scene, only when the tour pack supplies an intro OBJECT (a plain string is skipped,
        // so Tasman's string intro/outro don't turn into scenes).
        if (is_array($pack['intro'] ?? null)) {
            $lesson->scenes()->create($this->galleryScenePayload($pack['intro'], $voyageId, 'intro', $order++));
        }

        foreach ($catalog['legs'] as $i => $leg) {
            $stop = $pack['legs'][$i]['stop'] ?? [];
            $images = $this->resolveImages($stop['images'] ?? [], $voyageId, "leg{$i}");
            $pins = array_slice(array_column($images, 'url'), 0, self::MAX_PINS);

            $lesson->scenes()->create([
                'order' => $order++,
                'kind' => 'voyage',
                'status' => 'ready',
                'location' => $stop['place'] ?? null,
                'config' => [
                    'voyage' => $voyageId,
                    'leg' => $i,
                    'view' => 'flat',
                    'intro' => $i === 0,
                    'stop_images' => $pins,
                    'gallery' => [
                        'title' => $stop['title'] ?? ($stop['place'] ?? ''),
                        'date_label' => $stop['date_label'] ?? '',
                        'story' => $stop['story'] ?? '',
                        'images' => $images,
                    ],
                ],
            ]);
        }

        // A "real end" context slide after the landfalls (the return + consequences).
        if (is_array($pack['outro'] ?? null)) {
            $lesson->scenes()->create($this->galleryScenePayload($pack['outro'], $voyageId, 'outro', $order++));
        }

        return $lesson->refresh();
    }

    /** True when the catalog voyage is lesson-ready (has legs). Used to populate UI pickers. */
    public function isPlayable(string $voyageId): bool
    {
        // A question, not an assertion: an id that isn't in the catalogue at all is simply not
        // playable. catalogEntry() throws for an unknown id, which would turn a UI availability
        // check into a 500.
        try {
            $entry = $this->catalogEntry($voyageId);
        } catch (RuntimeException) {
            return false;
        }

        return ! empty($entry['legs']);
    }

    /**
     * Voyages that can become a lesson right now (have legs), as [id => name] for a UI picker.
     *
     * @return array<string,string>
     */
    public function playableVoyages(): array
    {
        $out = [];
        foreach ($this->catalog() as $v) {
            if (! empty($v['legs']) && ! empty($v['id'])) {
                $out[$v['id']] = $v['name'] ?? $v['id'];
            }
        }

        return $out;
    }

    /**
     * A standalone story/context slide (kind 'gallery') — the "real start"/"real end" of a lesson.
     * Rendered full-bleed by renderGallery in the player, exactly like the landfall gallery.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function galleryScenePayload(array $data, string $voyageId, string $bucket, int $order): array
    {
        return [
            'order' => $order,
            'kind' => 'gallery',
            'status' => 'ready',
            'location' => $data['place'] ?? null,
            'config' => [
                'title' => $data['title'] ?? '',
                'date_label' => $data['date_label'] ?? '',
                'story' => $data['story'] ?? '',
                'images' => $this->resolveImages($data['images'] ?? [], $voyageId, $bucket),
            ],
        ];
    }

    /**
     * Resolve an image list ([url string] or [{url,credit}]) into [{url,credit}] entries, hosting
     * any remote image on Cloudinary (deterministic public_id per voyage/bucket/slot so rebuilds
     * overwrite rather than duplicate). $bucket is a stable label, e.g. "leg2", "intro", "outro".
     *
     * @param  array<int,mixed>  $images
     * @return array<int,array{url:string,credit:string}>
     */
    private function resolveImages(array $images, string $voyageId, string $bucket): array
    {
        $out = [];
        foreach (array_values($images) as $slot => $img) {
            $url = is_array($img) ? (string) ($img['url'] ?? '') : (string) $img;
            $credit = is_array($img) ? (string) ($img['credit'] ?? '') : '';
            if ($url === '') {
                continue;
            }

            if (preg_match('#^https?://#i', $url)) {
                $publicId = "lessons/{$voyageId}/{$bucket}-{$slot}";
                $url = $this->cloud->uploadFromUrl($url, "lessons/{$voyageId}", $publicId);
            } elseif (! str_starts_with($url, '/')) {
                $url = "/lessons/{$voyageId}/{$url}";
            }

            $out[] = ['url' => $url, 'credit' => $credit];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function catalog(): array
    {
        $json = json_decode(File::get(resource_path('js/timemap/voyages.json')), true);

        return is_array($json['voyages'] ?? null) ? $json['voyages'] : [];
    }

    /** @return array<string,mixed> */
    private function catalogEntry(string $voyageId): array
    {
        foreach ($this->catalog() as $v) {
            if (($v['id'] ?? null) === $voyageId) {
                return $v;
            }
        }

        throw new RuntimeException("Voyage '{$voyageId}' not found in voyages.json.");
    }

    /** @return array<string,mixed> */
    private function tourPack(string $voyageId): array
    {
        $path = resource_path("js/timemap/{$voyageId}-tour.json");
        if (! File::exists($path)) {
            return [];
        }
        $json = json_decode(File::get($path), true);

        return is_array($json) ? $json : [];
    }
}
