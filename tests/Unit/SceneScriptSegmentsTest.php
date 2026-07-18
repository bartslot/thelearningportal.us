<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Scene;
use PHPUnit\Framework\TestCase;

class SceneScriptSegmentsTest extends TestCase
{
    private function scene(string $script, ?array $alignment = null, ?int $duration = null): Scene
    {
        $scene = new Scene();
        $scene->script_segment = $script;
        $scene->audio_alignment = $alignment;
        $scene->duration_seconds = $duration;

        return $scene;
    }

    public function test_single_paragraph_splits_into_sentences(): void
    {
        $scene = $this->scene(
            "In 1254, John stood in Utrecht. He dreamed of a great tower. Would his vision take shape?",
        );

        $segments = $scene->scriptSegments();

        $this->assertCount(3, $segments);
        $this->assertStringStartsWith('In 1254', $segments[0]['text']);
        $this->assertStringStartsWith('He dreamed', $segments[1]['text']);
    }

    public function test_dialogue_quotes_stay_on_one_line(): void
    {
        $scene = $this->scene(
            "The crowd gathered. 'We will build a tower!' he declared. The people cheered.",
        );

        $segments = $scene->scriptSegments();
        $texts = array_column($segments, 'text');

        $this->assertContains("'We will build a tower!' he declared.", $texts);
    }

    public function test_multi_paragraph_still_splits_on_paragraphs(): void
    {
        $scene = $this->scene("First paragraph here.\n\nSecond paragraph here.");

        $segments = $scene->scriptSegments();

        $this->assertCount(2, $segments);
        $this->assertSame('First paragraph here.', $segments[0]['text']);
        $this->assertSame('Second paragraph here.', $segments[1]['text']);
    }

    public function test_empty_script_returns_no_segments(): void
    {
        $this->assertSame([], $this->scene('   ')->scriptSegments());
    }

    public function test_timecodes_follow_audio_alignment_and_ascend(): void
    {
        // one alignment entry per character; sentence 2 starts at char ~12
        $script = 'Alpha beta gamma. Delta epsilon zeta.';
        $alignment = [];
        for ($i = 0, $n = mb_strlen($script); $i < $n; $i++) {
            $alignment[] = ['character' => mb_substr($script, $i, 1), 'start_time' => $i * 0.5];
        }

        $segments = $this->scene($script, $alignment)->scriptSegments();

        $this->assertCount(2, $segments);
        $this->assertSame('00:00', $segments[0]['timecode']);
        $this->assertGreaterThan($segments[0]['start'], $segments[1]['start']);   // ascending
    }

    // ── scriptParagraphs (per-paragraph editor boxes) ─────────────────────

    public function test_paragraphs_split_on_blank_line_only(): void
    {
        // Two sentences in one paragraph stay together; a blank line starts a new paragraph.
        $scene = $this->scene("First sentence. Second sentence.\n\nA new paragraph here.");

        $paras = $scene->scriptParagraphs();

        $this->assertCount(2, $paras);
        $this->assertSame('First sentence. Second sentence.', $paras[0]['text']);
        $this->assertSame('A new paragraph here.', $paras[1]['text']);
    }

    public function test_paragraph_keeps_internal_soft_newlines(): void
    {
        // A single newline is a soft break inside the paragraph — it must survive (not split).
        $scene = $this->scene("Line one\nline two, same paragraph.\n\nSecond paragraph.");

        $paras = $scene->scriptParagraphs();

        $this->assertCount(2, $paras);
        $this->assertSame("Line one\nline two, same paragraph.", $paras[0]['text']);
    }

    public function test_empty_script_returns_no_paragraphs(): void
    {
        $this->assertSame([], $this->scene('   ')->scriptParagraphs());
    }
}
