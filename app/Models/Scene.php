<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scene extends Model
{
    protected $fillable = [
        'lesson_id', 'order', 'kind', 'config',
        'game_type', 'quiz_question_count', 'quiz_timing', 'strategy_game_id', 'team_count',
        'year', 'location', 'script_segment',
        'image_prompt', 'image_path', 'skybox_image_path', 'image_style',
        'skybox_blur', 'skybox_opacity', 'background_color', 'scene_view',
        'animation_clip_id',
        'audio_path', 'audio_alignment', 'audio_script_hash',
        'duration_seconds', 'game_segment_index',
        'status', 'upscale_status', 'error_message',
        'world_labs_status', 'world_labs_operation_id',
        'world_pano_path', 'world_spz_path', 'world_glb_path',
        'world_semantics',
        'world_y_offset', 'world_scale', 'world_char_scale',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'config' => 'array',
            'audio_alignment' => 'array',
            'duration_seconds' => 'integer',
            'game_segment_index' => 'integer',
            'quiz_question_count' => 'integer',
            'strategy_game_id' => 'integer',
            'team_count' => 'integer',
            'skybox_blur' => 'float',
            'skybox_opacity' => 'float',
            'world_semantics' => 'array',
            'world_y_offset' => 'float',
            'world_scale' => 'float',
            'world_char_scale' => 'float',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function animationClip(): BelongsTo
    {
        return $this->belongsTo(AnimationClip::class);
    }

    public function strategyGame(): BelongsTo
    {
        return $this->belongsTo(StrategyGame::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Map-block fields resolved from a lesson's curated topic (polity QID + representative year).
     * Used both by the composer (add Map block) and the pipeline (default map block).
     *
     * @return array{config: array{qid: ?string, year: ?int, playback_mode: string}, year: ?int, location: ?string}
     */
    public static function mapPayloadForLesson(Lesson $lesson): array
    {
        $qid = str_starts_with((string) $lesson->topic_id, 'polity:')
            ? substr((string) $lesson->topic_id, strlen('polity:'))
            : null;

        $year = null;
        $location = null;
        if ($qid) {
            $topic = \App\Models\Corpus\Topic::resilient(
                fn () => \App\Models\Corpus\Topic::find($lesson->topic_id)
            );
            if ($topic) {
                // Mid-life year gives the most representative borders for the polity.
                $start = $topic->era_start;
                $end = $topic->era_end;
                $year = ($start !== null && $end !== null) ? (int) (($start + $end) / 2) : ($start ?? $end);
                $location = $topic->region_label;
            }
        }

        return [
            'config' => ['qid' => $qid, 'year' => $year, 'playback_mode' => 'interactive'],
            'year' => $year,
            'location' => $location,
        ];
    }

    /**
     * Insert the standard map block as the lesson's opening scene (the "second screen", right
     * after the title). Shifts existing scenes down by one. No-op without a polity topic.
     */
    public static function insertDefaultMapBlock(Lesson $lesson): ?self
    {
        $payload = self::mapPayloadForLesson($lesson);
        if (empty($payload['config']['qid'])) {
            return null;   // only catalog/polity lessons get an accurate map block
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($lesson, $payload) {
            // Make room at order 1 (two-phase shift avoids the (lesson_id, order) unique clash).
            $existing = $lesson->scenes()->ordered()->get();
            foreach ($existing as $idx => $s) {
                $s->update(['order' => -1 * ($idx + 1)]);
            }
            foreach ($existing as $idx => $s) {
                $s->update(['order' => $idx + 2]);
            }

            return self::create($payload + [
                'lesson_id' => $lesson->id,
                'order' => 1,
                'kind' => 'map',
                'image_style' => $lesson->image_style,
                'status' => 'ready',
            ]);
        });
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function hasFreshAudio(): bool
    {
        return $this->audio_path !== null
            && $this->audio_script_hash === sha1((string) $this->script_segment);
    }
}
