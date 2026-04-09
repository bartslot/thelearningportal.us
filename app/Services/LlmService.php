<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LlmService
{
    /**
     * Generate a lesson title, script, and quiz questions in a single API call.
     *
     * Returns ['title' => string, 'script' => string, 'questions' => array] or null on total failure.
     */
    public function generate(
        string  $facts,
        string  $topic,
        string  $gradLevel,
        ?string $tone = null,
        ?string $details = null,
    ): ?array {
        $toneInstruction = $tone
            ? "Voice/personality: {$tone}. Let this shape HOW things are said — word choice, rhythm, sentence length — not just the subject matter. Avoid labelling the tone; show it through the writing itself."
            : 'Voice/personality: clear and engaging, appropriate for the grade level.';

        $detailsInstruction = $details
            ? "Teacher requirements: {$details}. Follow these exactly. Do not add anything beyond what is specified here."
            : '';

        $animationTagInstructions = <<<'TAGS'

ANIMATION TAGS — MOVEMENT AND EMOTION
======================================

Embed animation tags directly inside the "script" text to control how the
avatar moves and speaks. Tags are part of the narration — write them inline,
not as stage directions.

[walk] ... [/walk]
Use for scene transitions, moving through events, opening/closing a segment.
2–4 per lesson. Wrap 1–3 sentences. Do NOT combine with [excited]/[serious]/[whisper].
Example: [walk] For centuries the Roman Republic had been the envy of the world.
Senators debated, laws were passed. But by 44 BC one man had changed all of that. [/walk]

[excited] ... [/excited]
Use for genuinely jaw-dropping moments: battle victories, discoveries, plot twists.
Max 2 per lesson. 1–2 sentences only. Must follow or precede a calmer passage.
Example: [excited] Against all odds, 300 Spartans held back over 100,000 soldiers for three full days! [/excited]

[serious] ... [/serious]
Use for deaths, human cost, moral weight, injustice, tragedy.
Max 2 per lesson. 1–3 sentences. Do NOT immediately follow [excited].
Example: [serious] Thousands of people were enslaved to build those monuments.
Their names were never recorded. Their stories were never told. [/serious]

[whisper] ... [/whisper]
Use for secrets, conspiracies, rumours, behind-the-scenes facts, broken fourth wall.
Max 2 per lesson. 1–2 sentences only. Always return to normal narration immediately after.
Example: [whisper] Between you and me — most historians think Brutus never actually wanted Caesar dead.
He was talked into it. And he regretted it for the rest of his life. [/whisper]

[point] — single sentence. Use when drawing attention to something specific.
[nod]   — single sentence. Use when affirming a fact or answering a rhetorical question.
[gesture] — single sentence. Use for general expressive emphasis when explaining or enumerating.
Use [point], [nod], [gesture] 2–4 times total per lesson. No closing tag. One sentence each.
Do NOT stack gesture tags on consecutive sentences.

RULES FOR ALL TAGS:
- Never invent tags not listed above.
- [walk][excited][serious][whisper] must always be closed.
- Tags must not overlap or nest.
- Tags wrap whole sentences only — never open mid-sentence.
- Most of the lesson should be untagged. Tags are accents, not wallpaper.
- Grade 3–5: favour [excited] and [whisper]. Keep [serious] brief.
- Grade 6–8: balance all four emotion tags.
- Grade 9–12: [serious] and [whisper] carry more weight; [excited] must feel earned.
TAGS;

        $systemPrompt = <<<PROMPT
You are writing a short educational narration for {$gradLevel} students.

RULES — follow exactly, in order of priority:
1. ONLY use facts from the source text provided. Never invent facts, dates, names, or events.
2. If a fact is not in the source, omit it. Do not guess.
3. Write plain, flowing prose — no headings, no bullet points, no markdown, no stage directions, no labels.
4. Every sentence must be natural spoken English. The text will be read aloud.
5. Length: 200–260 words. No more. Cut anything that does not add understanding.
6. {$toneInstruction}
7. {$detailsInstruction}
8. Clarity over creativity. Only add depth where it helps the student understand — never to fill space.
{$animationTagInstructions}

Where it genuinely improves the story, wrap sentences in emotion tags to guide
voice delivery. Available tags: [serious], [cheerful], [excited], [empathetic],
[whispering], [narrative]. Close each tag with [/tag]. Example:
[serious]The army faced devastating losses.[/serious]
Use these sparingly — untagged text is delivered in a natural narrative tone.
Never invent tags not in the list above.

After the narration, write 4 multiple-choice quiz questions. Each question must:
- Be answerable from the narration only
- Test a specific fact or idea, not vague comprehension
- Have exactly 4 options, one correct
- Include a one-sentence explanation of the correct answer

Output ONLY valid JSON — no text before or after:
{
  "title": "A concise, specific lesson title (max 8 words)",
  "script": "Full plain-text narration here...",
  "questions": [
    {
      "question": "Specific factual question?",
      "options": ["Correct answer", "Plausible wrong answer", "Plausible wrong answer", "Plausible wrong answer"],
      "correct_index": 0,
      "explanation": "One sentence explaining why this is correct."
    }
  ]
}
PROMPT;

        $userPrompt = "Source facts about {$topic}:\n\n{$facts}\n\nGenerate the lesson JSON now.";

        // Local Ollama first (free, no latency cost) → Anthropic as cloud fallback
        $result = $this->tryOllama($systemPrompt, $userPrompt)
            ?? $this->tryAnthropic($systemPrompt, $userPrompt);

        if ($result !== null) {
            return $result;
        }

        Log::warning("LlmService: all providers failed for topic '{$topic}', using fallback");
        return [
            'title'     => $topic,
            'script'    => $this->buildFallbackScript($facts, $topic, $gradLevel),
            'questions' => [],
        ];
    }

    // ── Providers ────────────────────────────────────────────────────────────

    private function tryAnthropic(string $systemPrompt, string $userPrompt): ?array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'       => 'claude-haiku-4-5-20251001',
                    'max_tokens'  => 1500,
                    'temperature' => 0.4,
                    'system'      => $systemPrompt,
                    'messages'    => [['role' => 'user', 'content' => $userPrompt]],
                ]);

            if ($response->successful()) {
                $text = $response->json('content.0.text');
                if (is_string($text) && trim($text) !== '') {
                    return $this->parseJsonResponse($text);
                }
            }

            Log::warning('LlmService: Anthropic error', ['status' => $response->status(), 'body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::warning('LlmService: Anthropic call failed — ' . $e->getMessage());
        }

        return null;
    }

    private function tryOllama(string $systemPrompt, string $userPrompt): ?array
    {
        $url   = rtrim((string) config('services.ollama.url', 'http://localhost:11434'), '/') . '/v1/chat/completions';
        $model = config('services.ollama.model', 'llama3.1:8b');

        try {
            $response = Http::timeout(120)
                ->withToken('ollama')
                ->post($url, [
                    'model'       => $model,
                    'temperature' => 0.4,
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                ]);

            if ($response->successful()) {
                $text = $response->json('choices.0.message.content');
                if (is_string($text) && trim($text) !== '') {
                    return $this->parseJsonResponse($text);
                }
            }

            Log::warning('LlmService: Ollama error', ['status' => $response->status()]);
        } catch (\Throwable $e) {
            Log::warning('LlmService: Ollama call failed — ' . $e->getMessage());
        }

        return null;
    }

    // ── Response parsing ─────────────────────────────────────────────────────

    private function parseJsonResponse(string $text): ?array
    {
        // Strip markdown code fences
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/m', '', $text) ?? $text;
        $text = trim($text);

        // Extract first JSON object in case there's surrounding prose
        if (preg_match('/\{[\s\S]+\}/u', $text, $matches)) {
            $text = $matches[0];
        }

        $data = json_decode($text, true);

        if (! is_array($data)) {
            Log::warning('LlmService: could not parse JSON response', ['raw' => mb_substr($text, 0, 300)]);
            return null;
        }

        $title  = isset($data['title'])  && is_string($data['title'])  ? trim($data['title'])  : '';
        $script = isset($data['script']) && is_string($data['script']) ? trim($data['script']) : '';

        if ($script === '') {
            Log::warning('LlmService: parsed JSON had empty script');
            return null;
        }

        return [
            'title'     => $title !== '' ? $this->cleanText($title) : null,
            'script'    => $this->cleanText($script),
            'questions' => $this->normalizeQuestions(
                isset($data['questions']) && is_array($data['questions']) ? $data['questions'] : []
            ),
        ];
    }

    /**
     * Remove any leftover markdown headings or formatting from generated text.
     */
    private function cleanText(string $text): string
    {
        $lines = preg_split('/\n+/', $text) ?: [];
        $clean = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Strip markdown headings
            $line = preg_replace('/^#{1,6}\s+/', '', $line) ?? $line;
            // Strip bold / italic
            $line = preg_replace('/\*\*(.*?)\*\*/', '$1', $line) ?? $line;
            $line = preg_replace('/\*(.*?)\*/', '$1', $line) ?? $line;
            // Drop lines that are only a section label (short title-case phrase, no punctuation)
            if (preg_match('/^[A-Z][A-Za-z\s]{2,40}$/', $line) && ! preg_match('/[.!?,;]/', $line)) {
                continue;
            }

            $clean[] = trim($line);
        }

        return implode(' ', array_filter($clean));
    }

    /**
     * Validate and normalise questions returned by the LLM.
     */
    private function normalizeQuestions(array $questions): array
    {
        $normalized = [];

        foreach ($questions as $q) {
            if (! is_array($q)) {
                continue;
            }

            $question     = trim((string) ($q['question'] ?? ''));
            $options      = array_values(array_map('trim', is_array($q['options'] ?? null) ? $q['options'] : []));
            $correctIndex = is_numeric($q['correct_index'] ?? null) ? (int) $q['correct_index'] : null;

            if ($question === '' || count($options) < 4 || $correctIndex === null) {
                continue;
            }

            $normalized[] = [
                'question'      => $question,
                'options'       => array_slice($options, 0, 4),
                'correct_index' => max(0, min(3, $correctIndex)),
                'explanation'   => isset($q['explanation']) ? trim((string) $q['explanation']) : null,
            ];

            if (count($normalized) >= 4) {
                break;
            }
        }

        return $normalized;
    }

    // ── Fallback ─────────────────────────────────────────────────────────────

    private function buildFallbackScript(string $facts, string $topic, string $gradLevel): string
    {
        $cleanFacts = trim(preg_replace('/\s+/', ' ', $facts));
        $sentences  = preg_split('/(?<=[.!?])\s+/', $cleanFacts) ?: [];
        $sentences  = array_values(array_filter(array_map('trim', $sentences)));
        $highlights = implode(' ', array_slice($sentences, 0, 5));

        return $highlights !== ''
            ? $highlights
            : "Today we explore {$topic}. This is an important topic for {$gradLevel} students to understand.";
    }
}
