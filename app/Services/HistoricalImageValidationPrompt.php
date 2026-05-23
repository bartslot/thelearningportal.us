<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Scene;

/**
 * Produces the system + user prompts for the historical-accuracy validation step
 * that runs before every image generation. The LLM returns a structured JSON
 * object that the image prompt builder uses to include only period-accurate
 * visuals and explicitly exclude anachronisms.
 */
final class HistoricalImageValidationPrompt
{
    public static function system(): string
    {
        return <<<SYS
You are a historical accuracy consultant for educational image generation.
Given a scene description, topic, and a list of proposed visual elements,
you must validate what is period-accurate and flag what is risky or anachronistic.

Return ONLY a JSON object with this exact shape — no markdown, no prose:
{
  "historicalPeriod": string (specific year or decade range, e.g. "1789–1799"),
  "location": string (city, region, country),
  "accurateVisuals": string[] (visuals confirmed period-accurate from your knowledge),
  "riskyOrUncertainVisuals": string[] (visuals that may be anachronistic or uncertain),
  "anachronismsToAvoid": string[] (objects that definitely did not exist in this period),
  "recommendedScene": string (a precise one-sentence image prompt combining the accurate visuals)
}

Rules:
- Be strict. If you are uncertain whether something existed in the period, put it in riskyOrUncertainVisuals, not accurateVisuals.
- anachronismsToAvoid must be comprehensive: include obvious items (cars, phones, electric lights) AND subtle ones specific to this period (e.g. "Eiffel Tower" for anything before 1889, "tricolore flags" for French scenes before July 1789).
- recommendedScene must be safe: only use items from accurateVisuals. Never include items from riskyOrUncertainVisuals or anachronismsToAvoid.
- Do not invent historical facts.
SYS;
    }

    public static function user(Scene $scene, array $proposedVisuals): string
    {
        $brief    = $scene->lesson->outline['scene_briefs'][$scene->order - 1] ?? [];
        $topic    = $scene->lesson->topic;
        $subject  = $scene->lesson->subject ?? 'History';
        $year     = $scene->year     ?? ($brief['year']     ?? 'unknown');
        $location = $scene->location ?? ($brief['location'] ?? 'unknown');
        $purpose  = $brief['scenePurpose'] ?? $brief['beat'] ?? '';

        $visualsList = implode("\n", array_map(fn ($v) => "- {$v}", $proposedVisuals));

        return <<<USR
Topic: {$topic}
Subject: {$subject}
Year / period: {$year}
Location: {$location}
Scene purpose: {$purpose}

Proposed visual elements to validate:
{$visualsList}

Validate these visuals for historical accuracy. Return the JSON object now.
USR;
    }
}
