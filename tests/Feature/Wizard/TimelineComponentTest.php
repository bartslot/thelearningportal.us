<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class TimelineComponentTest extends TestCase
{
    /**
     * Regression: the timeline's @push('scripts') block once contained the literal text "@stack"
     * inside a JS // comment. Blade compiles directives EVERYWHERE (even inside <script>), so it
     * compiled that comment as a real @stack directive → "Undefined property:
     * Illuminate\View\Factory::$yieldPushContent" → every wizard / lesson page 500'd.
     * Rendering the component throws if any stray directive sneaks back in.
     */
    public function test_timeline_component_renders_without_stray_blade_directives(): void
    {
        // render() compiles AND executes the @push('scripts') block (Blade evaluates pushed content
        // to buffer it) — a stray @stack/@push directive hidden in the JS comment throws
        // "Undefined property: $yieldPushContent" right here. A clean render is the regression guard.
        $html = View::make('components.lesson.timeline', [
            'scenes' => collect(),
            'selectedSceneId' => null,
            'editable' => true,
        ])->render();

        $this->assertStringContainsString('timeline-track', $html);
    }
}
