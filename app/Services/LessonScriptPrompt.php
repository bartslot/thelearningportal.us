<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NarrativeFramework;
use App\Models\Lesson;
use App\Models\Scene;
use Illuminate\Support\Collection;

/**
 * Whole-lesson narration prompt: ONE LLM call writes every scene's script so the story
 * stays causally connected (but/therefore) instead of N isolated diorama descriptions.
 *
 * Replaces the per-scene SceneScriptPrompt path. No bracket tags of any kind — the TTS
 * model (eleven_multilingual_v2) does not support audio tags, and clean prose keeps the
 * character-timing alignment usable for shot syncing.
 */
final class LessonScriptPrompt
{
    /**
     * Gold-standard contrast pair shown to the model. The single highest-leverage fix for
     * "boring": the model must SEE the difference between a diorama and a story.
     */
    private const EXEMPLAR = <<<'EXAMPLE'
BAD (never write this — scenery with nobody in it):
"You are standing in the bustling Forum of Rome. Marble columns tower above you. Scrolls
and tablets lie nearby, filled with records of achievements. Colorful banners flutter in
the breeze, representing the power of the First Triumvirate."

GOOD (write this — a named person acting under pressure):
"Julius Caesar stops at the Senate door. Yesterday a soothsayer grabbed his arm and hissed:
'Beware the Ides of March.' Caesar laughed it off. But this morning his wife Calpurnia
begged him to stay home — she dreamed of his statue running with blood. He hesitates.
Then he pushes the door open anyway. Inside, sixty senators turn to look at him.
One of them is his closest friend, Brutus."

Why the good one works: a real person makes a decision, there is dialogue, there is a
"but" turn, and the student now WANTS to know what happens next.

The example above is from a DIFFERENT lesson — never copy its sentences, characters, or
imagery into your output. Learn the technique, not the content.
EXAMPLE;

    public static function system(Lesson $lesson): string
    {
        $language = self::contentLanguage($lesson);
        $framework = $lesson->narrative_framework instanceof NarrativeFramework
            ? $lesson->narrative_framework
            : (NarrativeFramework::tryFrom((string) $lesson->narrative_framework) ?? NarrativeFramework::default());
        // Spel-verhaal lessons write scripts against the Choice→Consequence spine, not plain branching.
        $spine = StorySpine::for($framework, $lesson->game_type);

        $exemplar = self::EXEMPLAR;

        return <<<SYS
You are a master history storyteller writing narration for a voice actor. You write ALL
scenes of the lesson in one pass so the story flows as one connected narrative.

STORY RULES — these are hard requirements, not suggestions:
1. Every narration scene must show a NAMED historical person DOING something: deciding,
   arguing, fleeing, building, betraying. Never describe scenery without a person acting in it.
2. {$spine->abtRule} A scene may never merely follow the previous one — it must happen
   BECAUSE of it, or push AGAINST it.
3. At least one line of spoken dialogue or an inner decision ("she knew that...") in every
   two scenes. Short quotes, historically plausible, grounded in the source.
4. Each scene ends on a hook: an open question, a threat, a decision not yet made — except
   the final scene, which resolves the story, lands its meaning, and quietly recaps: every
   learning objective returns as one memorable line the student will carry home. Never a
   bullet-point summary; the recap stays inside the story's voice, built ONLY from THIS
   lesson's events — never reuse lines from the example below.
5. Second person ("you") is allowed as a camera, but the CHARACTERS carry the story. Never
   open a scene with "you are standing" or "you find yourself".
6. 80–150 words per narration scene. Game scenes get a 40–80 word setup that raises the stakes.

FACT RULES:
- Only use facts from the provided source text. If uncertain, omit — never invent facts,
  dates, names, or events. Plausible dialogue is allowed but must not assert unsourced facts.
- Cover every learning objective listed: a student who hears the full story must be able to
  answer each one. Weave the objectives into the drama — never lecture.

DELIVERY RULES:
- Plain flowing prose. No headings, no scene numbers, no stage directions, no markdown,
  and NO bracket tags of any kind. Emotion comes from the writing itself.
- Every sentence must sound natural read aloud.
- Write all narration in {$language}.

{$exemplar}

Return ONLY valid JSON, no text before or after:
{
  "scenes": [
    { "order": integer (matching the scene order you were given), "script": "narration text" }
  ]
}
Every scene in the input list must appear exactly once in the output.
SYS;
    }

    /**
     * @param  Collection<int, Scene>  $scenes  ordered, map blocks excluded
     * @param  list<array<string, mixed>>  $briefs  outline scene_briefs aligned by position
     */
    public static function user(Lesson $lesson, Collection $scenes, array $briefs, string $sourceText, array $critiqueIssues = []): string
    {
        $objectives = collect($lesson->outline['learning_objectives'] ?? [])
            ->map(fn (array $objective) => '- '.($objective['id'] ?? '?').': '.($objective['text'] ?? ''))
            ->implode("\n");
        $objectivesBlock = $objectives !== ''
            ? "Learning objectives (the story must teach ALL of these):\n{$objectives}"
            : '';

        $sceneLines = $scenes->values()->map(function (Scene $scene, int $index) use ($briefs): string {
            $brief = $briefs[$index] ?? [];
            $parts = [
                "Scene order={$scene->order}".($scene->kind === 'game' ? ' (GAME — 40-80 word setup only)' : ''),
                'beat: '.($brief['beat'] ?? 'continue the story'),
            ];
            if (! empty($brief['conflict'])) {
                $parts[] = 'conflict: '.$brief['conflict'];
            }
            if (! empty($brief['characters'])) {
                $parts[] = 'characters: '.implode(', ', (array) $brief['characters']);
            }
            if (! empty($brief['objective_ids'])) {
                $parts[] = 'objectives: '.implode(', ', (array) $brief['objective_ids']);
            }
            if (! empty($brief['historicalFacts'])) {
                $parts[] = 'facts: '.implode(' | ', (array) $brief['historicalFacts']);
            }
            if ($scene->year || $scene->location) {
                $parts[] = 'setting: '.trim(($scene->year ?? '').' '.($scene->location ?? ''));
            }

            return implode("\n  ", $parts);
        })->implode("\n\n");

        $revisionBlock = '';
        if ($critiqueIssues !== []) {
            $issues = implode("\n", array_map(fn ($issue) => "- {$issue}", $critiqueIssues));
            $revisionBlock = "\nYour previous draft was rejected. Fix these specific problems:\n{$issues}\n";
        }

        $protagonist = trim((string) $lesson->protagonist_name);
        $protagonistLine = $protagonist !== '' ? "Protagonist the student follows: {$protagonist}" : '';

        $focusLine = '';
        if (is_array($lesson->focus_tags) && count($lesson->focus_tags) > 0) {
            $labels = \App\Lessons\FocusTags::labels($lesson->focus_tags);
            $focusLine = "Keep the narrative lens on: {$labels}";
        }

        // Source first, then the brief, then the revision notes. This prompt is sent twice
        // whenever the critique pass rejects a draft; leading with the article means the
        // rewrite reuses the cached prefix instead of re-reading the whole source. The
        // rejection notes must stay last — they are the only thing that changed.
        return <<<USR
Source text (authoritative — only use facts from here):
"""
{$sourceText}
"""

Topic: {$lesson->topic}
Grade level: {$lesson->grade_level}
Tone: {$lesson->tone}
{$protagonistLine}
{$focusLine}

{$objectivesBlock}

Scenes to write (in order):

{$sceneLines}
{$revisionBlock}
Write the full lesson narration now as one connected story. Return the JSON only.
USR;
    }

    /** Human-readable content language from the lesson owner's locale. */
    public static function contentLanguage(Lesson $lesson): string
    {
        return match ($lesson->teacher?->locale ?? 'en') {
            'nl' => 'Dutch',
            default => 'English',
        };
    }
}
