<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Support\Collection;

/**
 * Names a lesson's scenes as short chapter titles (Micrio-style serial-tour), from
 * the narration, in ONE batched LLM call. Used to fill Scene::chapter_name so the
 * player's chapter bar/list reads like "Zware arbeid" · "Eén dag feest" instead of
 * a repeating "Utrecht 1321" caption. Names are editable afterwards.
 */
final class ChapterNamer
{
    private const MAX_SNIPPET = 400;
    private const MAX_NAME = 80;

    public function __construct(private readonly OpenAiLlmService $llm) {}

    /**
     * Generate + store chapter names for a lesson's narration scenes.
     *
     * @param  bool  $force  re-name scenes that already have a name
     * @return int number of scenes named
     */
    public function nameLesson(Lesson $lesson, bool $force = false): int
    {
        $scenes = $lesson->scenes()
            ->whereIn('kind', ['narration', 'cover'])
            ->orderBy('order')
            ->get()
            ->filter(fn ($s) => trim((string) $s->script_segment) !== '')
            ->filter(fn ($s) => $force || trim((string) $s->chapter_name) === '')
            ->values();

        if ($scenes->isEmpty()) {
            return 0;
        }

        $names = $this->generate($scenes);
        $count = 0;
        foreach ($scenes as $scene) {
            $name = trim((string) ($names[$scene->order] ?? ''));
            if ($name === '') {
                continue;
            }
            $scene->chapter_name = mb_substr($name, 0, self::MAX_NAME);
            $scene->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param  Collection<int,\App\Models\Scene>  $scenes
     * @return array<int,string> order => name
     */
    private function generate(Collection $scenes): array
    {
        $items = $scenes->map(fn ($s) => [
            'order' => $s->order,
            'text' => mb_substr(trim((string) $s->script_segment), 0, self::MAX_SNIPPET),
        ])->values()->all();

        $system = 'You title chapters for a short, narrated history lesson — like the chapter list '
            .'of a museum audio tour. Each title is 2–4 words, Title Case, concrete and distinct, '
            .'in the SAME language as the narration. No numbering, no quotes, no trailing punctuation.';

        $user = "For each scene below, return a short chapter title.\n"
            .'Respond as JSON: {"names":[{"order":<int>,"name":"<title>"}, …]}.'."\n\n"
            .json_encode($items, JSON_UNESCAPED_UNICODE);

        try {
            $res = $this->llm->json($system, $user);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $out = [];
        foreach ($res['names'] ?? [] as $row) {
            if (isset($row['order'])) {
                $out[(int) $row['order']] = (string) ($row['name'] ?? '');
            }
        }

        return $out;
    }
}
