<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Services\LessonOutlinePrompt;
use App\Services\OpenAiLlmService;
use App\Services\WikipediaService;
use App\Services\WorldHistoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildLessonOutline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const METER_DELTA_LIMIT = 25;

    private const METER_START_MIN = 0;

    private const METER_START_MAX = 100;

    private const METER_COUNT_MIN = 3;

    private const METER_COUNT_MAX = 4;

    private const MAX_BRANCH_GROUPS = 3;

    private const BRANCH_ROLES = ['question', 'option_a', 'option_b'];

    /** Safety net when the LLM returns unusable meters — the pipeline must never fail on them. */
    private const DEFAULT_METERS = [
        ['key' => 'morale',   'label' => 'Moreel',      'icon' => '🔥', 'start' => 60],
        ['key' => 'troops',   'label' => 'Manschappen', 'icon' => '👥', 'start' => 60],
        ['key' => 'supplies', 'label' => 'Voorraden',   'icon' => '📦', 'start' => 60],
        ['key' => 'support',  'label' => 'Steun',       'icon' => '❤️', 'start' => 60],
    ];

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $lessonId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with('source', 'strategyGame')->findOrFail($this->lessonId);

        try {
            // ── Step 0: Catalog stories carry their own curated narrative sources — no fetch. ──
            $story = $lesson->story_id ? \App\Models\Story::with('sources')->find($lesson->story_id) : null;
            if ($story && $lesson->source && (string) $lesson->source->extracted_text === '') {
                $lesson->source->update([
                    'extracted_text' => $story->combinedSourceText(),
                    'wikipedia_topic' => $lesson->topic,
                ]);
                Log::info("BuildLessonOutline #{$lesson->id}: grounded in catalog story '{$story->slug}'");
            }

            // ── Step 1: Fetch internet sources (worldhistory.org → wikipedia fallback) ──
            $source = $lesson->source;
            if (! $story && $source && in_array($source->kind, ['internet', 'wikipedia', 'both'], true)
                && (string) $source->extracted_text === '') {

                $lesson->update(['status' => LessonStatus::FetchingSources]);

                // A1: if the lesson came from the curated catalog it carries the EXACT Wikipedia
                // article URL — fetch that page directly (no slug-guessing, no disambiguation drift).
                $text = null;
                $fromWH = false;
                if (filled($lesson->wikipedia_source)) {
                    $text = app(WikipediaService::class)->fetchByUrl($lesson->wikipedia_source);
                    if ($text !== null) {
                        Log::info("BuildLessonOutline #{$lesson->id}: fetched exact catalog article {$lesson->wikipedia_source}");
                    }
                }

                // Try World History Encyclopedia next — richer, more educational content
                $worldHistory = app(WorldHistoryService::class);
                if ($text === null) {
                    $text = $worldHistory->fetchFacts($lesson->topic);
                    $fromWH = $text !== null;
                }

                if ($fromWH) {
                    Log::info("BuildLessonOutline #{$lesson->id}: fetched from worldhistory.org");

                    // Try to grab the article's hero image while we have the HTML cached
                    $hero = $worldHistory->fetchHeroImage($lesson->id);
                    if ($hero) {
                        $source->update([
                            'hero_image_url' => $hero['url'],
                            'hero_image_path' => $hero['path'],
                        ]);
                        Log::info(sprintf(
                            'BuildLessonOutline #{%d}: hero image saved — colorful=%s (%dx%d)',
                            $lesson->id, $hero['colorful'] ? 'yes' : 'no', $hero['width'], $hero['height'],
                        ));
                    }
                } elseif ($text === null) {
                    // Fallback to Wikipedia by topic name (only when nothing fetched yet)
                    $text = app(WikipediaService::class)->fetchFacts($lesson->topic) ?? '';
                    Log::info("BuildLessonOutline #{$lesson->id}: fell back to Wikipedia");
                }
                $text ??= '';

                // The teacher's free-text focus ("De Domtoren van Utrecht — bouw, de storm van
                // 1674…") often names a SPECIFIC subject with its own article that the broad topic
                // article barely covers. The no-hallucination rule then makes the LLM ignore the
                // focus entirely — a lesson "about the Dom Tower" without the Dom Tower. Fetch the
                // focus as a second source so the outline has real facts to honour it with.
                $focus = trim((string) ($lesson->focus ?? ''));
                if ($focus !== '' && mb_strlen($focus) > 6) {
                    // Teachers write prose ("De Domtoren van Utrecht — bouw (1254-1382), de storm
                    // van 1674") that Wikipedia search chokes on. Try the raw text, then the first
                    // clause (before any dash/colon/comma), then the first five words.
                    $firstClause = trim(preg_split('/[—–:;,(]|\s-\s/u', $focus, 2)[0] ?? '');
                    $firstWords = implode(' ', array_slice(preg_split('/\s+/u', $firstClause) ?: [], 0, 5));
                    $focusText = null;
                    foreach (array_unique(array_filter([$focus, $firstClause, $firstWords])) as $q) {
                        if (mb_strlen($q) < 4) {
                            continue;
                        }
                        $focusText = app(WikipediaService::class)->fetchFacts($q);
                        if (is_string($focusText) && trim($focusText) !== '') {
                            break;
                        }
                    }
                    if (is_string($focusText) && trim($focusText) !== '') {
                        $text = trim($text)."\n\n== Teacher's focus: {$focus} ==\n".mb_substr($focusText, 0, 6000);
                        Log::info("BuildLessonOutline #{$lesson->id}: appended focus source for \"{$focus}\"");
                    }
                }

                // For 'both' mode the document text was already extracted and stored
                $combined = $source->kind === 'both'
                    ? trim($source->extracted_text."\n\n".$text)
                    : $text;

                $source->update(['extracted_text' => $combined, 'wikipedia_topic' => $lesson->topic]);
            }

            // Title-screen background: download the Wikipedia lead image (full-res) for catalog
            // lessons. Hotlink-safe from upload.wikimedia.org; stored locally for offline playback.
            if (filled($lesson->wikipedia_source) && empty($lesson->title_bg_path)) {
                $imgUrl = app(WikipediaService::class)->fetchLeadImageUrl($lesson->wikipedia_source);
                if ($imgUrl) {
                    try {
                        $bytes = \Illuminate\Support\Facades\Http::timeout(20)
                            ->withUserAgent('TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com)')
                            ->get($imgUrl)->body();
                        if ($bytes !== '') {
                            $ext = pathinfo(parse_url($imgUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
                            $path = "lessons/{$lesson->id}/title-bg.{$ext}";
                            \Illuminate\Support\Facades\Storage::disk('public')->put($path, $bytes);
                            $lesson->update(['title_bg_path' => $path]);
                            Log::info("BuildLessonOutline #{$lesson->id}: title background saved from {$imgUrl}");
                        }
                    } catch (Throwable $e) {
                        Log::warning("BuildLessonOutline #{$lesson->id}: title bg download failed — {$e->getMessage()}");
                    }
                }
            }

            $lesson->update(['status' => LessonStatus::Outlining]);

            // Defensive: previous attempts (manual retry, queue retry, user clicked Generate twice)
            // may have left Scene rows around. The (lesson_id, order) unique index would then trip
            // on re-insert. Wipe and rebuild from scratch.
            $lesson->scenes()->delete();

            $lesson->refresh();
            $sourceText = (string) ($lesson->source?->extracted_text ?? '');

            $hasGame = (bool) $lesson->include_game;
            $outline = $llm->json(
                system: LessonOutlinePrompt::system($hasGame, $lesson->game_type),
                user: LessonOutlinePrompt::user($lesson, $sourceText, $story),
            );

            // Curated stories are the pedagogical source of truth: their reviewed objectives
            // always override whatever the model emitted.
            if ($story && ! empty($story->learning_objectives)) {
                $outline['learning_objectives'] = $story->learning_objectives;
            }

            // Story game (spel-verhaal): validate + persist meters/roles into lesson.game_config;
            // the resulting meter keys drive per-option delta sanitizing below.
            $isStoryGame = ($lesson->game_type ?? null) === 'story_game';
            $meterKeys = [];
            $updates = [
                'title' => $outline['title'] ?? $lesson->topic,
                'outline' => $outline,
                'status' => LessonStatus::ScenesGenerating,
            ];
            if ($isStoryGame) {
                $meters = $this->validatedMeters($lesson, $outline['meters'] ?? null);
                $meterKeys = array_column($meters, 'key');
                $updates['game_config'] = array_merge($lesson->game_config ?? [], [
                    'meters' => $meters,
                    'roles' => $this->sanitizedRoles($outline['roles'] ?? null),
                    'fail_threshold' => 0,
                ]);
            }
            $lesson->update($updates);

            // Defensive: the outline prompt tells the model to omit game scenes when games are
            // disabled, but LLMs don't reliably obey a negative instruction. Drop any game briefs
            // when the teacher turned games off so an unwanted quiz/strategy scene never appears.
            $briefs = $outline['scene_briefs'] ?? [];
            if (! $lesson->include_game) {
                $briefs = array_values(array_filter(
                    $briefs,
                    fn ($brief) => ($brief['kind'] ?? 'narration') !== 'game',
                ));
            }

            // Story game: the LLM's branch structure is only usable when every group is COMPLETE
            // (question + option_a + option_b) and every option moves the meters. An orphaned
            // question renders a choice overlay with no buttons (player dead-end); options with
            // no branch_effects leave the class meters frozen for the whole lesson.
            if ($isStoryGame) {
                // The outline model reliably emits the QUESTION briefs but often drops the two
                // option briefs (observed across runs). Complete orphaned groups with a repair
                // call first; sanitize guards whatever repair couldn't save; then guarantee
                // meter effects on every option.
                $briefs = $this->completeOrphanedGroups($llm, $briefs, $meterKeys);
                $briefs = $this->sanitizeBranchGroups($briefs);
                $briefs = $this->ensureBranchEffects($llm, $briefs, $meterKeys, $lesson);
            }

            // Ignore any "order" the LLM emitted — assign sequential 1-based positions so
            // we never trip the (lesson_id, order) unique index when the model repeats numbers.
            $scenes = [];
            foreach ($briefs as $idx => $brief) {
                $branchAttributes = $isStoryGame ? $this->branchAttributes($brief, $meterKeys) : [];
                $scenes[] = Scene::create($branchAttributes + [
                    'lesson_id' => $lesson->id,
                    'order' => $idx + 1,
                    'kind' => $brief['kind'] ?? 'narration',
                    'year' => $brief['year'] ?? null,
                    'location' => $brief['location'] ?? null,
                    'image_prompt' => $brief['image_prompt_seed'] ?? null,
                    'image_style' => $lesson->image_style,
                    'game_segment_index' => $brief['game_segment_index'] ?? null,
                    'game_type' => ($brief['kind'] ?? null) === 'game' ? ($lesson->game_type ?? 'strategy') : null,
                    'quiz_question_count' => ($brief['kind'] ?? null) === 'game' && $lesson->game_type === 'quiz'
                        ? ($lesson->quiz_question_count ?? 4)
                        : null,
                    'quiz_timing' => ($brief['kind'] ?? null) === 'game' && $lesson->game_type === 'quiz'
                        ? ($lesson->quiz_timing ?? 'after')
                        : null,
                    'strategy_game_id' => ($brief['kind'] ?? null) === 'game' && ($lesson->game_type ?? null) === 'strategy'
                        ? $lesson->strategy_game_id
                        : null,
                    'team_count' => ($brief['kind'] ?? null) === 'game' && ($lesson->game_type ?? null) === 'strategy'
                        ? $lesson->team_count
                        : null,
                    'status' => 'pending',
                ]);
            }

            // Standard map block (the "second screen", right after the title) for catalog lessons.
            // Inserted at order 1, shifting content scenes down; teachers can reorder/delete it.
            // No generation jobs needed — it renders the historical atlas live.
            Scene::insertDefaultMapBlock($lesson->fresh());

            // If we have a colorful hero image from worldhistory.org, pre-assign it to
            // scene 1 so AI image generation is skipped for that scene.
            $heroPath = null;
            $source = $lesson->fresh()->source;
            if ($source?->hero_image_path) {
                // Re-check colorfulness by reading stored metadata via a quick saturation test
                $bytes = \Illuminate\Support\Facades\Storage::disk('public')->get($source->hero_image_path);
                if ($bytes) {
                    $img = @imagecreatefromstring($bytes);
                    if ($img) {
                        // Quick sample: 5×5 grid
                        $w = imagesx($img);
                        $h = imagesy($img);
                        $sat = 0;
                        $n = 0;
                        foreach (range(0, 4) as $ix) {
                            foreach (range(0, 4) as $iy) {
                                $px = imagecolorat($img, (int) ($w * ($ix + 0.5) / 5), (int) ($h * ($iy + 0.5) / 5));
                                $r = ($px >> 16) & 0xFF;
                                $g = ($px >> 8) & 0xFF;
                                $b = $px & 0xFF;
                                $mx = max($r, $g, $b);
                                $mn = min($r, $g, $b);
                                $d = 255 - abs($mx + $mn - 255);
                                $sat += $d > 0 ? ($mx - $mn) / $d * 255 : 0;
                                $n++;
                            }
                        }
                        imagedestroy($img);
                        if ($n > 0 && ($sat / $n) >= 40) {
                            $heroPath = $source->hero_image_path;
                        }
                    }
                }
            }

            // Attach hero image to the first content scene (image generation skips it).
            if ($heroPath && isset($scenes[0])) {
                $scenes[0]->update(['image_path' => $heroPath]);
            }

            // One whole-lesson script pass (story coherence + critique loop) — it fans out
            // the per-scene image/audio batch and the quiz itself once scripts exist, and
            // (for a story-game with a print pack) the spelpakket PDF, whose event cards need
            // the generated script text — so that dispatch lives at the END of GenerateLessonScript.
            GenerateLessonScript::dispatch($lesson->id);
        } catch (Throwable $e) {
            $lesson->update([
                'status' => LessonStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Validate the LLM's meters (3-4 items, key/label/start required, start clamped 0-100).
     * Garbage meters fall back to the default set — the pipeline never fails on them.
     *
     * @return list<array{key: string, label: string, icon: string, start: int}>
     */
    private function validatedMeters(Lesson $lesson, mixed $raw): array
    {
        $meters = [];
        foreach (is_array($raw) ? $raw : [] as $meter) {
            if (! is_array($meter)) {
                continue;
            }
            $key = is_string($meter['key'] ?? null) ? trim($meter['key']) : '';
            $label = is_string($meter['label'] ?? null) ? trim($meter['label']) : '';
            if ($key === '' || $label === '' || ! is_numeric($meter['start'] ?? null)) {
                continue;
            }
            $meters[] = [
                'key' => $key,
                'label' => $label,
                'icon' => is_string($meter['icon'] ?? null) ? trim($meter['icon']) : '',
                'start' => max(self::METER_START_MIN, min(self::METER_START_MAX, (int) $meter['start'])),
            ];
        }

        $count = count($meters);
        if ($count < self::METER_COUNT_MIN || $count > self::METER_COUNT_MAX) {
            Log::warning("BuildLessonOutline #{$lesson->id}: LLM meters unusable ({$count} valid) — using default meter set");

            return self::DEFAULT_METERS;
        }

        return $meters;
    }

    /**
     * Keep only roles with a title; coerce flavor/power to trimmed strings.
     *
     * @return list<array{title: string, flavor: string, power: string}>
     */
    private function sanitizedRoles(mixed $raw): array
    {
        $roles = [];
        foreach (is_array($raw) ? $raw : [] as $role) {
            $title = is_array($role) && is_string($role['title'] ?? null) ? trim($role['title']) : '';
            if ($title === '') {
                continue;
            }
            $roles[] = [
                'title' => $title,
                'flavor' => trim((string) ($role['flavor'] ?? '')),
                'power' => trim((string) ($role['power'] ?? '')),
            ];
        }

        return $roles;
    }

    /**
     * The outline model emits question briefs but frequently OMITS their two option briefs.
     * For every group that has a question but is missing options, ask the LLM (one call for
     * all orphans) to write the two options — label, one-sentence beat, meter effects — and
     * insert them right after their question. Groups that still come back broken are handled
     * by sanitizeBranchGroups afterwards.
     *
     * @param  list<array<string, mixed>>  $briefs
     * @param  list<string>  $meterKeys
     * @return list<array<string, mixed>>
     */
    private function completeOrphanedGroups(OpenAiLlmService $llm, array $briefs, array $meterKeys): array
    {
        // Map group -> present roles + the question brief's index.
        $groups = [];
        foreach ($briefs as $i => $brief) {
            $branch = $brief['branch'] ?? null;
            if (! is_array($branch)) {
                continue;
            }
            $group = is_numeric($branch['group'] ?? null) ? (int) $branch['group'] : 0;
            $role = $branch['role'] ?? null;
            if ($group >= 1 && $group <= self::MAX_BRANCH_GROUPS && in_array($role, self::BRANCH_ROLES, true)) {
                $groups[$group]['roles'][$role] = true;
                if ($role === 'question') {
                    $groups[$group]['qIndex'] = $i;
                }
            }
        }

        $orphans = [];
        foreach ($groups as $g => $info) {
            if (isset($info['qIndex']) && (! isset($info['roles']['option_a']) || ! isset($info['roles']['option_b']))) {
                $q = $briefs[$info['qIndex']];
                $orphans[] = [
                    'group' => $g,
                    'question_summary' => mb_substr((string) ($q['description'] ?? $q['summary'] ?? ''), 0, 300),
                    'question_label' => (string) (($q['branch'] ?? [])['choice_label'] ?? ''),
                ];
            }
        }
        if ($orphans === []) {
            return $briefs;
        }

        try {
            $out = $llm->json(
                'You complete choice points in a classroom history story game. For every group given, '
                .'write the TWO options the class can choose between. The options differ in APPROACH, '
                .'never in historical OUTCOME. Same language as the question summary. Meters: '
                .implode(', ', $meterKeys).'. Return JSON {"groups":[{"group":<int>,'
                .'"option_a":{"choice_label":"<short imperative>","description":"<1-2 sentence scene beat>",'
                .'"branch_effects":{"deltas":{<meterKey>:<int -15..15, at least one non-zero>},'
                .'"consequence_line":"<1 dramatic sentence>","historical_note":"<1 factual sentence>"}},'
                .'"option_b":{...same shape...}}]}',
                json_encode($orphans, JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            Log::warning('outline: orphan-group completion call failed', ['error' => $e->getMessage()]);

            return $briefs;
        }

        $byGroup = [];
        foreach (($out['groups'] ?? []) as $fix) {
            if (is_numeric($fix['group'] ?? null) && is_array($fix['option_a'] ?? null) && is_array($fix['option_b'] ?? null)) {
                $byGroup[(int) $fix['group']] = $fix;
            }
        }

        // Weave the new option briefs in right after their question (iterate a copy; track offset).
        $result = [];
        foreach ($briefs as $i => $brief) {
            $result[] = $brief;
            $branch = $brief['branch'] ?? null;
            if (! is_array($branch) || ($branch['role'] ?? null) !== 'question') {
                continue;
            }
            $g = (int) ($branch['group'] ?? 0);
            $fix = $byGroup[$g] ?? null;
            if (! $fix || (isset($groups[$g]['roles']['option_a']) && isset($groups[$g]['roles']['option_b']))) {
                continue;
            }
            foreach (['option_a', 'option_b'] as $role) {
                if (isset($groups[$g]['roles'][$role])) {
                    continue;   // that option existed; only add the missing one(s)
                }
                $opt = $fix[$role];
                $result[] = [
                    'kind' => 'narration',
                    'description' => (string) ($opt['description'] ?? ''),
                    'image_prompt_seed' => (string) ($opt['description'] ?? ''),
                    'branch' => [
                        'group' => $g,
                        'role' => $role,
                        'choice_label' => (string) ($opt['choice_label'] ?? ''),
                    ],
                    'branch_effects' => is_array($opt['branch_effects'] ?? null) ? $opt['branch_effects'] : null,
                ];
            }
            Log::info('outline: completed orphaned branch group', ['group' => $g]);
        }

        return $result;
    }

    /**
     * Keep branch markers only for COMPLETE groups: exactly one question, one option_a and one
     * option_b. Briefs in orphaned/duplicated groups keep their narrative text but lose the
     * marker — they become plain narration instead of a player dead-end.
     *
     * @param  list<array<string, mixed>>  $briefs
     * @return list<array<string, mixed>>
     */
    private function sanitizeBranchGroups(array $briefs): array
    {
        $groups = [];
        foreach ($briefs as $brief) {
            $branch = $brief['branch'] ?? null;
            if (! is_array($branch)) {
                continue;
            }
            $group = is_numeric($branch['group'] ?? null) ? (int) $branch['group'] : 0;
            $role = $branch['role'] ?? null;
            if ($group >= 1 && $group <= self::MAX_BRANCH_GROUPS && in_array($role, self::BRANCH_ROLES, true)) {
                $groups[$group][$role] = ($groups[$group][$role] ?? 0) + 1;
            }
        }

        $complete = array_keys(array_filter(
            $groups,
            fn (array $roles) => ($roles['question'] ?? 0) === 1
                && ($roles['option_a'] ?? 0) === 1
                && ($roles['option_b'] ?? 0) === 1,
        ));

        return array_values(array_map(function (array $brief) use ($complete) {
            $group = is_numeric(($brief['branch'] ?? [])['group'] ?? null) ? (int) $brief['branch']['group'] : 0;
            if (is_array($brief['branch'] ?? null) && ! in_array($group, $complete, true)) {
                \Log::warning('outline: dropped incomplete branch group', ['group' => $group]);
                unset($brief['branch'], $brief['branch_effects']);
            }

            return $brief;
        }, $briefs));
    }

    /**
     * Every option in a complete branch group must move the class meters — otherwise the
     * story game plays with a frozen HUD. Options missing branch_effects get ONE cheap LLM
     * repair call (labels + meters -> deltas/consequence); if that fails, a deterministic
     * mild fallback (option_a nudges the first meter up, option_b down) keeps the game alive.
     *
     * @param  list<array<string, mixed>>  $briefs
     * @param  list<string>  $meterKeys
     * @return list<array<string, mixed>>
     */
    private function ensureBranchEffects(OpenAiLlmService $llm, array $briefs, array $meterKeys, Lesson $lesson): array
    {
        if ($meterKeys === []) {
            return $briefs;
        }

        $missing = [];
        foreach ($briefs as $i => $brief) {
            $role = ($brief['branch'] ?? [])['role'] ?? null;
            if (in_array($role, ['option_a', 'option_b'], true) && ! is_array($brief['branch_effects'] ?? null)) {
                $missing[$i] = [
                    'index' => $i,
                    'group' => (int) $brief['branch']['group'],
                    'role' => $role,
                    'label' => (string) ($brief['branch']['choice_label'] ?? ''),
                    'summary' => mb_substr((string) ($brief['description'] ?? $brief['summary'] ?? ''), 0, 200),
                ];
            }
        }
        if ($missing === []) {
            return $briefs;
        }

        $repaired = [];
        try {
            $out = $llm->json(
                'You add game-meter consequences to choice options in a classroom history story game. '
                .'Meters (keys): '.implode(', ', $meterKeys).'. For EVERY option given, return JSON '
                .'{"options":[{"index":<int>,"deltas":{<meterKey>:<int -10..10>},"consequence_line":"<one short sentence, '
                .'same language as the option label>","historical_note":"<one short factual sentence>"}]}. '
                .'Deltas must be non-zero for at least one meter and reflect the choice\'s realistic consequence.',
                json_encode(array_values($missing), JSON_UNESCAPED_UNICODE),
            );
            foreach (($out['options'] ?? []) as $opt) {
                if (is_numeric($opt['index'] ?? null) && is_array($opt['deltas'] ?? null)) {
                    $repaired[(int) $opt['index']] = $opt;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('outline: branch-effects repair call failed', ['error' => $e->getMessage()]);
        }

        foreach ($missing as $i => $meta) {
            $fix = $repaired[$i] ?? null;
            $briefs[$i]['branch_effects'] = is_array($fix) ? [
                'deltas' => $fix['deltas'],
                'consequence_line' => (string) ($fix['consequence_line'] ?? ''),
                'historical_note' => (string) ($fix['historical_note'] ?? ''),
            ] : [
                // Deterministic fallback: a mild, visible swing so the HUD reacts to every choice.
                'deltas' => [$meterKeys[0] => $meta['role'] === 'option_a' ? 5 : -5],
                'consequence_line' => $meta['label'],
                'historical_note' => '',
            ];
        }

        return $briefs;
    }

    /**
     * Scene attributes for a story-game brief's branch marker: branch columns for valid
     * question/option briefs, plus sanitized branch_effects in scene.config on options.
     *
     * @param  array<string, mixed>  $brief
     * @param  list<string>  $meterKeys
     * @return array<string, mixed>
     */
    private function branchAttributes(array $brief, array $meterKeys): array
    {
        $branch = $brief['branch'] ?? null;
        if (! is_array($branch)) {
            return [];
        }

        $group = is_numeric($branch['group'] ?? null) ? (int) $branch['group'] : 0;
        $role = $branch['role'] ?? null;
        if ($group < 1 || $group > self::MAX_BRANCH_GROUPS || ! in_array($role, self::BRANCH_ROLES, true)) {
            return [];
        }

        $isOption = $role !== 'question';
        $choiceLabel = is_string($branch['choice_label'] ?? null) ? trim($branch['choice_label']) : '';
        $attributes = [
            'branch_group' => $group,
            'branch_role' => $role,
            'branch_choice_label' => $isOption && $choiceLabel !== '' ? $choiceLabel : null,
        ];

        if ($isOption && is_array($brief['branch_effects'] ?? null)) {
            $attributes['config'] = [
                'branch_effects' => $this->sanitizedBranchEffects($brief['branch_effects'], $meterKeys),
            ];
        }

        return $attributes;
    }

    /**
     * Clamp deltas to ±METER_DELTA_LIMIT, drop unknown meter keys, default missing keys to 0.
     *
     * @param  array<string, mixed>  $effects
     * @param  list<string>  $meterKeys
     * @return array{deltas: array<string, int>, consequence_line: string, historical_note: string}
     */
    private function sanitizedBranchEffects(array $effects, array $meterKeys): array
    {
        $rawDeltas = is_array($effects['deltas'] ?? null) ? $effects['deltas'] : [];
        $deltas = [];
        foreach ($meterKeys as $key) {
            $value = is_numeric($rawDeltas[$key] ?? null) ? (int) $rawDeltas[$key] : 0;
            $deltas[$key] = max(-self::METER_DELTA_LIMIT, min(self::METER_DELTA_LIMIT, $value));
        }

        return [
            'deltas' => $deltas,
            'consequence_line' => trim((string) ($effects['consequence_line'] ?? '')),
            'historical_note' => trim((string) ($effects['historical_note'] ?? '')),
        ];
    }
}
