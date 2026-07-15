<?php

declare(strict_types=1);

namespace App\Services\Corpus;

use App\Models\Lesson;
use App\Models\Scene;
use App\Services\OpenAiLlmService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Derives the "match target" for a scene background — the tag set the picker scores
 * candidate artworks against. Computed once per scene (LLM tags the scene text), cached
 * on scene.config['match_target'] and only recomputed when the scene text/topic changes.
 *
 * Target shape: ['core' => [], 'themes' => [], 'actor_qids' => [], 'era' => ?string, 'sig' => string]
 */
class SceneMatchTargetService
{
    public function __construct(private OpenAiLlmService $llm) {}

    public function for(Scene $scene, Lesson $lesson): array
    {
        $sig = $this->signature($scene, $lesson);
        $cached = $scene->config['match_target'] ?? null;
        if (is_array($cached) && ($cached['sig'] ?? null) === $sig) {
            return $cached;
        }

        $target = $this->derive($scene, $lesson, $sig);

        $config = $scene->config ?? [];
        $config['match_target'] = $target;
        $scene->update(['config' => $config]);

        return $target;
    }

    private function signature(Scene $scene, Lesson $lesson): string
    {
        return md5(implode('|', [
            $lesson->title ?? '',           // the lesson anchors the whole search
            $lesson->topic ?? '',
            $lesson->historical_figure ?? '',
            $lesson->topic_id ?? '',
            $lesson->era ?? '',
            $scene->script_segment ?? '',
            $scene->image_prompt ?? '',
            $scene->location ?? '',
            $scene->year ?? '',
        ]));
    }

    private function derive(Scene $scene, Lesson $lesson, string $sig): array
    {
        $vocab = $this->vocab();

        // Actors: the lesson's figure/polity QID + the scene's linked territory QID.
        $actors = [];
        if (preg_match('/^(?:figure|polity):(Q\d+)$/', (string) $lesson->topic_id, $m)) {
            $actors[] = $m[1];
        }
        $territory = $scene->config['qid'] ?? null;
        if (is_string($territory) && preg_match('/^Q\d+$/', $territory)) {
            $actors[] = $territory;
        }

        // Core subject + themes + era: one constrained LLM call, ANCHORED ON THE LESSON.
        // The lesson title/topic/figure is the domain; the scene refines within it. This is
        // what stops a thin scene ("Flanders 1988") from drifting to random modern landscapes.
        $core = $themes = [];
        $era = null;
        $regions = ['european'];
        if ($vocab['depicts'] !== []) {
            try {
                $out = $this->llm->json($this->system($vocab), $this->user($scene, $lesson));
                $core = array_values(array_intersect((array) ($out['core'] ?? []), $vocab['depicts']));
                $themes = array_values(array_intersect((array) ($out['themes'] ?? []), $vocab['theme']));
                $era = in_array($out['era'] ?? null, $vocab['era'], true) ? $out['era'] : null;
                $valid = ['european', 'americas', 'asia', 'africa'];
                $r = array_values(array_intersect((array) ($out['regions'] ?? []), $valid));
                $regions = $r !== [] ? $r : ['european'];
            } catch (Throwable $e) {
                // Graceful: no core → picker falls back to the era/location blend.
            }
        }

        return [
            'core' => array_slice($core, 0, 2),
            'themes' => array_slice($themes, 0, 3),
            'actor_qids' => array_values(array_unique($actors)),
            // Prefer the LLM's lesson-grounded era; fall back to the scene year, then lesson era.
            'era' => $era ?? $this->eraOf($scene->year) ?? $this->eraOf($lesson->era),
            // Region(s) the lesson SUBJECT spans — colonisation/exploration reach beyond Europe.
            'regions' => $regions,
            'sig' => $sig,
        ];
    }

    /** Controlled vocab (depicts + theme + era slugs), cached — the picker's allowed tags. */
    private function vocab(): array
    {
        return Cache::remember('corpus.match_vocab.v2', 3600, function () {
            try {
                $rows = DB::connection('pgsql_corpus')->table('curriculum_themes')->get(['tag', 'facet']);

                return [
                    'depicts' => $rows->where('facet', 'depicts')->pluck('tag')->all(),
                    'theme' => $rows->where('facet', 'theme')->pluck('tag')->all(),
                    'era' => $rows->where('facet', 'era')->pluck('tag')->all(),
                ];
            } catch (Throwable $e) {
                return ['depicts' => [], 'theme' => [], 'era' => []];
            }
        });
    }

    private function system(array $vocab): string
    {
        return "You choose the background image a history lesson scene needs.\n"
            ."The LESSON is the anchor — its subject/period is the domain; the scene refines within it.\n"
            ."If the scene text is thin or its year looks wrong, lean on the LESSON to decide.\n"
            ."Return strict JSON {\"core\":[],\"themes\":[],\"era\":\"\",\"regions\":[]} using ONLY these exact slugs:\n"
            .'DEPICTS: '.implode(', ', $vocab['depicts'])."\n"
            .'THEME: '.implode(', ', $vocab['theme'])."\n"
            .'ERA: '.implode(', ', $vocab['era'])."\n"
            ."REGIONS: european, americas, asia, africa\n"
            ."core = the 1-2 DEPICTS slugs the background MUST literally show — ONLY the defining\n"
            ."  subject(s). Prefer a single tag; add a 2nd only if genuinely essential (e.g. a sea\n"
            ."  battle = naval-battle). Do NOT pad with secondary details.\n"
            ."themes = 1-2 THEME slugs the scene serves.\n"
            ."era = the historical period the LESSON is about — infer it from the lesson title/topic/\n"
            ."  figure ('French Revolution & Napoleon' => napoleonic; 'Dutch Golden Age' => golden-age;\n"
            ."  'Roman Empire' => antiquity; 'Reformation' => renaissance). Use the scene's year ONLY\n"
            ."  when the scene is clearly set in a DIFFERENT period than the lesson; IGNORE the scene\n"
            ."  year when it looks like placeholder/default data (e.g. a 1900s year on a lesson about\n"
            ."  antiquity). The lesson subject wins.\n"
            ."regions = which world region(s) the lesson's SUBJECT spans, 1-3. Default ['european'].\n"
            ."  Add others when the subject genuinely reaches them: colonisation / age of discovery /\n"
            ."  Atlantic trade => ['european','americas']; VOC / spice trade => ['european','asia'];\n"
            ."  a purely European topic (French Revolution, Reformation) stays ['european'].\n"
            ."Never invent a slug; if unsure, omit.";
    }

    private function user(Scene $scene, Lesson $lesson): string
    {
        $lessonCtx = trim(implode("\n", array_filter([
            $lesson->title ? "LESSON: {$lesson->title}" : null,
            $lesson->topic ? "TOPIC: {$lesson->topic}" : null,
            $lesson->historical_figure ? "FIGURE: {$lesson->historical_figure}" : null,
            $lesson->era ? "LESSON ERA: {$lesson->era}" : null,
        ])));
        $sceneText = trim(implode('. ', array_filter([
            $scene->script_segment, $scene->image_prompt,
        ])));

        return $lessonCtx."\n\nSCENE — place: ".($scene->location ?: '(unset)')
            .', scene-year hint (often a placeholder, weigh lightly): '.($scene->year ?: '(unset)')."\n"
            .mb_substr($sceneText !== '' ? $sceneText : '(no script yet — infer the background from the lesson)', 0, 900);
    }

    /** Map the scene's year label to an era bucket (matches artworks.depicted_era). */
    private function eraOf(?string $yearLabel): ?string
    {
        $raw = trim((string) $yearLabel);
        if ($raw === '') {
            return null;
        }
        $bce = (bool) preg_match('/\b(BCE|BC)\b/i', $raw);
        if (preg_match('/(\d+)\s*(?:st|nd|rd|th)\s*century/i', $raw, $m)) {
            $year = ((int) $m[1] - 1) * 100 + 50;
        } elseif (preg_match('/\b(\d{1,4})\b/', $raw, $m)) {
            $year = (int) $m[1];
        } else {
            return null;
        }
        $year = $bce ? -$year : $year;

        return match (true) {
            $year < 500 => 'antiquity',
            $year < 1400 => 'middle-ages',
            $year < 1580 => 'renaissance',
            $year < 1700 => 'baroque',
            $year < 1780 => 'enlightenment',
            $year < 1820 => 'napoleonic',
            $year < 1900 => 'nineteenth-century',
            default => 'modern',
        };
    }
}
