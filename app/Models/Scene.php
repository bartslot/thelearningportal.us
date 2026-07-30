<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Scene extends Model
{
    /** Deleting a scene is undoable, so the row (and its quiz questions) has to outlive the delete. */
    use SoftDeletes;

    protected $fillable = [
        'lesson_id', 'order', 'kind', 'config',
        'game_type', 'quiz_question_count', 'quiz_timing', 'strategy_game_id', 'team_count',
        'year', 'location', 'chapter_name', 'script_segment',
        'image_prompt', 'image_path', 'shots', 'skybox_image_path', 'skybox_candidates', 'image_style',
        'skybox_blur', 'skybox_opacity', 'background_color', 'kb_animated', 'kb_direction', 'scene_view',
        'animation_clip_id',
        'audio_path', 'audio_alignment', 'audio_script_hash', 'audio_locale',
        'duration_seconds', 'game_segment_index',
        'branch_group', 'branch_role', 'branch_choice_label',
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
            'shots' => 'array',
            'skybox_candidates' => 'array',
            'audio_alignment' => 'array',
            'duration_seconds' => 'integer',
            'game_segment_index' => 'integer',
            'branch_group' => 'integer',
            'quiz_question_count' => 'integer',
            'strategy_game_id' => 'integer',
            'team_count' => 'integer',
            'skybox_blur' => 'float',
            'skybox_opacity' => 'float',
            'kb_animated' => 'boolean',
            'world_semantics' => 'array',
            'world_y_offset' => 'float',
            'world_scale' => 'float',
            'world_char_scale' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // LessonPlayerController caches the whole eager-loaded player payload — SCENES INCLUDED —
        // for 30 minutes, and Lesson::booted only busts that key on a *lesson* save. So editing a
        // scene (its script, its background, a gallery's images, a map's labels) left the player
        // serving the previous version for up to half an hour: the teacher pressed Play in the
        // wizard and watched a lesson that no longer existed. Scene writes must bust it too.
        $forgetPlayerCache = static function (Scene $scene): void {
            $code = $scene->lesson_id
                ? Lesson::withTrashed()->whereKey($scene->lesson_id)->value('lesson_code')
                : null;

            if ($code) {
                Cache::forget('lesson.player.'.strtoupper((string) $code));
            }
        };

        static::saved($forgetPlayerCache);
        static::deleted($forgetPlayerCache);
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

    /**
     * Does this scene paint its own backdrop rather than a flat `image_path`?
     *
     * A map or voyage scene draws a live map; a panorama scene draws its own sphere. They look
     * finished to the teacher but carry no image_path, so "no image_path" must never be read as
     * "this scene has no background yet" — that is what used to refuse a picture on a map.
     */
    public function drawsOwnBackdrop(): bool
    {
        return in_array($this->kind, ['map', 'voyage'], true)
            || filled($this->skybox_image_path)
            || filled($this->world_pano_path);
    }

    /** Anything a layer can sit on: a generated image, a map, a panorama, a chosen colour. */
    public function hasBackdrop(): bool
    {
        return filled($this->image_path)
            || $this->drawsOwnBackdrop()
            || filled($this->background_color);
    }

    /** A teacher-picked painting or pasted image must not be overwritten by queued image generation. */
    public function hasManualBackground(): bool
    {
        $config = $this->config ?? [];
        $source = $config['background_source'] ?? null;
        $creditKind = $config['background_credit']['kind'] ?? null;

        return in_array($source, ['painting', 'url'], true)
            || in_array($creditKind, ['painting', 'city_map', 'url'], true);
    }

    /**
     * Short chapter name for the Micrio-style serial-tour player. Uses the stored
     * (auto-derived, editable) name; falls back to the location or a numbered label so
     * the chapter bar/list always has something readable.
     */
    public function chapterName(): string
    {
        $name = trim((string) $this->chapter_name);
        if ($name !== '') {
            return $name;
        }
        if (trim((string) $this->location) !== '') {
            return trim((string) $this->location);
        }

        return 'Chapter '.($this->order ?? '');
    }

    /**
     * The narration split into timed segments for the Script editing view — each
     * paragraph paired with the start time (seconds) of its first character, derived
     * from the character-level audio_alignment. Falls back to an even split across
     * duration_seconds when there's no alignment yet.
     *
     * @return list<array{text:string,start:float,timecode:string}>
     */
    public function scriptSegments(): array
    {
        $text = trim((string) $this->script_segment);
        if ($text === '') {
            return [];
        }

        // Split into sentences (respecting paragraph breaks as harder boundaries) so even a
        // single-paragraph script yields several timecoded lines, like the Figma 00:04/00:20.
        $chunks = [];
        foreach (preg_split('/\n\s*\n/', $text) ?: [$text] as $para) {
            $para = trim((string) $para);
            if ($para === '') {
                continue;
            }
            // Break after . ! ? (optionally a closing quote) ONLY when the next line starts a
            // new sentence — an uppercase letter or an opening quote. Both branches require
            // that lookahead so mid-sentence dialogue ("…tower!' he declared.") stays intact.
            $pattern = '/(?<=[.!?][\'"”’])\s+(?=[\p{Lu}"“‘\'])|(?<=[.!?])\s+(?=[\p{Lu}"“‘\'])/u';
            foreach (preg_split($pattern, $para) ?: [$para] as $sentence) {
                $sentence = trim((string) $sentence);
                if ($sentence !== '') {
                    $chunks[] = $sentence;
                }
            }
        }
        if ($chunks === []) {
            $chunks = [$text];
        }

        $align = is_array($this->audio_alignment) ? array_values($this->audio_alignment) : [];
        $duration = (float) ($this->duration_seconds ?: 0);
        $totalChars = max(1, mb_strlen($text));

        $segments = [];
        $cursor = 0;   // char offset into $text
        foreach ($chunks as $i => $chunk) {
            $at = mb_strpos($text, $chunk, $cursor);
            $at = $at === false ? $cursor : $at;
            $cursor = $at + mb_strlen($chunk);

            $start = $this->timeAtChar($align, $at, $duration, $totalChars, $i, count($chunks));
            $segments[] = [
                'text' => $chunk,
                'start' => $start,
                'timecode' => $this->formatTimecode($start),
                // Character position as a fraction of the whole script. The Script view uses this
                // to spread timecodes over the REAL audio length (the client knows the true
                // duration; duration_seconds is often null), so lines don't cluster at 00:00-04.
                'fraction' => round($at / $totalChars, 4),
            ];
        }

        return $segments;
    }

    /**
     * The narration split into PARAGRAPHS for the Script editing view — one entry per
     * blank-line-separated paragraph (its internal single newlines preserved as soft breaks),
     * paired with the start time of its first character. Each paragraph is one editable box and
     * one TTS topic; double-Enter in the editor splits a paragraph, single Enter is a soft break.
     *
     * @return list<array{text:string,start:float,timecode:string,fraction:float}>
     */
    public function scriptParagraphs(): array
    {
        $text = trim((string) $this->script_segment);
        if ($text === '') {
            return [];
        }

        $chunks = array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/', $text) ?: [$text]),
            fn ($p) => $p !== '',
        ));
        if ($chunks === []) {
            $chunks = [$text];
        }

        $align = is_array($this->audio_alignment) ? array_values($this->audio_alignment) : [];
        $duration = (float) ($this->duration_seconds ?: 0);
        $totalChars = max(1, mb_strlen($text));

        $paragraphs = [];
        $cursor = 0;
        foreach ($chunks as $i => $chunk) {
            $at = mb_strpos($text, $chunk, $cursor);
            $at = $at === false ? $cursor : $at;
            $cursor = $at + mb_strlen($chunk);

            $start = $this->timeAtChar($align, $at, $duration, $totalChars, $i, count($chunks));
            $paragraphs[] = [
                'text' => $chunk,
                'start' => $start,
                'timecode' => $this->formatTimecode($start),
                'fraction' => round($at / $totalChars, 4),
            ];
        }

        return $paragraphs;
    }

    /** Start time (s) for a character offset — from alignment, else proportional to duration. */
    private function timeAtChar(array $align, int $charOffset, float $duration, int $totalChars, int $index, int $count): float
    {
        if ($align !== []) {
            $entry = $align[min($charOffset, count($align) - 1)] ?? null;

            return (float) ($entry['start_time'] ?? $entry['startTime'] ?? 0.0);
        }
        if ($duration > 0 && $totalChars > 0) {
            return round($duration * ($charOffset / $totalChars), 2);
        }

        return $count > 0 ? round($index * ($duration ?: $count) / $count, 2) : 0.0;
    }

    private function formatTimecode(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
