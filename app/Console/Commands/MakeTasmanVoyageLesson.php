<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Build (or rebuild) the Abel Tasman voyage demo lesson: dated tour legs on the voyage map
 * (scene kind 'voyage') alternating with image galleries of the journal drawings (kind
 * 'gallery'). Content comes from resources/js/timemap/tasman-1642-tour.json; images from
 * public/lessons/tasman/. Idempotent: re-running rebuilds the same lesson's scenes.
 */
class MakeTasmanVoyageLesson extends Command
{
    protected $signature = 'lessons:make-tasman-voyage {--teacher= : Teacher email (default: first teacher)}';

    protected $description = 'Create/refresh the Abel Tasman voyage demo lesson (game_type=voyage)';

    /** Per-leg gallery images + map-pinned thumbs (files under public/lessons/tasman/). */
    private const LEG_IMAGES = [
        0 => ['gallery' => ['mauritius-voc.jpg', 'vloot-rennefeld.webp', 'voc-schip-dodo.jpg'], 'pins' => ['vloot-rennefeld.webp', 'mauritius-voc.jpg']],
        1 => ['gallery' => ['kaart-tasman-1644.jpg', 'tasman-gravure-1886.webp', 'kaart-tasman-reizen.jpg'], 'pins' => ['kaart-tasman-1644.jpg']],
        2 => ['gallery' => ['moordenaarsbaai-gilsemans.jpg', 'golden-bay-encounter.png', 'murderers-bay-sketch.webp'], 'pins' => ['moordenaarsbaai-gilsemans.jpg', 'golden-bay-encounter.png']],
        3 => ['gallery' => ['voc-schepen.png', 'duyfken-replica.jpg', 'tasman-portret-cuyp.jpg'], 'pins' => ['voc-schepen.png']],
        4 => ['gallery' => [], 'pins' => ['tasman-portret-cuyp.jpg', 'kaart-tasman-reizen.jpg']],
    ];

    public function handle(): int
    {
        $pack = json_decode(File::get(resource_path('js/timemap/tasman-1642-tour.json')), true);
        if (! is_array($pack) || empty($pack['legs'])) {
            $this->error('tasman-1642-tour.json missing or invalid.');

            return self::FAILURE;
        }
        $credits = collect(json_decode(File::get(public_path('lessons/tasman/credits.json')), true) ?: [])
            ->keyBy('file');

        $teacher = $this->option('teacher')
            ? User::where('email', $this->option('teacher'))->first()
            : User::where('role', 'teacher')->orderBy('id')->first();
        if (! $teacher) {
            $this->error('No teacher account found.');

            return self::FAILURE;
        }

        $lesson = Lesson::firstOrCreate(
            ['title' => $pack['title'], 'teacher_id' => $teacher->id],
            [
                'topic' => 'De reis van Abel Tasman (1642-1643)',
                'subject' => 'history',
                'grade_level' => 'K-7',
                'tone' => 'adventurous',
                'status' => LessonStatus::Previewable->value,
                'include_game' => false,
            ]
        );
        $lesson->update([
            'game_type' => 'voyage',
            'game_config' => ['voyage' => 'tasman-1642'],
            'status' => LessonStatus::Previewable->value,
        ]);

        // Rebuild scenes from scratch — the command owns this lesson's structure.
        $lesson->scenes()->forceDelete();   // hard: a soft-deleted row would still hold its (lesson_id, order) slot

        $img = fn (string $file): string => "/lessons/tasman/{$file}";
        $creditLine = fn (string $file): string => (string) ($credits[$file]['title'] ?? '');
        $order = 1;

        foreach ($pack['legs'] as $i => $leg) {
            $stop = $leg['stop'];
            $images = self::LEG_IMAGES[$i] ?? ['gallery' => [], 'pins' => []];

            // ONE scene per landfall: sail this leg, arrive at the stop, and carry the gallery
            // (title + story + images) inline as config.gallery — opened as a modal via the
            // landfall hotspot in the player. No more separate 'gallery' scenes.
            $lesson->scenes()->create([
                'order' => $order++,
                'kind' => 'voyage',
                'status' => 'ready',
                'location' => $stop['place'],
                'config' => [
                    'voyage' => 'tasman-1642',
                    'leg' => $i,
                    'view' => 'flat',
                    'intro' => $i === 0,
                    'stop_images' => array_map($img, $images['pins']),
                    'gallery' => [
                        'title' => $stop['title'] ?? $stop['place'],
                        'date_label' => $stop['date_label'] ?? '',
                        'story' => $stop['story'] ?? '',
                        'images' => array_map(
                            fn (string $f) => ['url' => $img($f), 'credit' => $creditLine($f)],
                            $images['gallery']
                        ),
                    ],
                ],
            ]);
        }

        $this->info("Lesson '{$lesson->title}' ready — code {$lesson->lesson_code}, {$lesson->scenes()->count()} scenes.");
        $this->line("Play: /lesson/{$lesson->lesson_code}");

        return self::SUCCESS;
    }
}
