<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lessons\FocusTags;
use PHPUnit\Framework\TestCase;

class FocusTagsTest extends TestCase
{
    public function test_all_returns_ordered_array_with_labels_and_emojis(): void
    {
        $all = FocusTags::all();

        $this->assertIsArray($all);
        $this->assertGreaterThanOrEqual(16, count($all));
        $this->assertArrayHasKey('power', $all);
        $this->assertArrayHasKey('label', $all['power']);
        $this->assertArrayHasKey('emoji', $all['power']);
    }

    public function test_sanitize_whitelists_against_all(): void
    {
        $slugs = ['power', 'war', 'unknown_slug', 'revolution'];
        $result = FocusTags::sanitize($slugs);

        $this->assertContains('power', $result);
        $this->assertContains('war', $result);
        $this->assertContains('revolution', $result);
        $this->assertNotContains('unknown_slug', $result);
    }

    public function test_sanitize_deduplicates(): void
    {
        $slugs = ['power', 'power', 'war', 'war', 'power'];
        $result = FocusTags::sanitize($slugs);

        $this->assertCount(2, $result);
        $this->assertContains('power', $result);
        $this->assertContains('war', $result);
    }

    public function test_sanitize_preserves_order(): void
    {
        $slugs = ['revolution', 'power', 'war'];
        $result = FocusTags::sanitize($slugs);

        $this->assertSame(['revolution', 'power', 'war'], $result);
    }

    public function test_sanitize_caps_at_three(): void
    {
        $slugs = ['power', 'war', 'revolution', 'rights', 'religion'];
        $result = FocusTags::sanitize($slugs);
        $result = FocusTags::cap($result);

        $this->assertCount(3, $result);
        $this->assertSame(['power', 'war', 'revolution'], $result);
    }

    public function test_sanitize_returns_values_keyed(): void
    {
        $slugs = ['power', 'war', 'revolution'];
        $result = FocusTags::sanitize($slugs);

        $this->assertSame([0, 1, 2], array_keys($result));
    }

    public function test_labels_joins_en_labels_comma_separated(): void
    {
        $slugs = ['power', 'war', 'revolution'];
        $result = FocusTags::labels($slugs);

        $this->assertStringContainsString('Power', $result);
        $this->assertStringContainsString('War', $result);
        $this->assertStringContainsString('Revolution', $result);
        $this->assertStringContainsString(', ', $result);
    }

    public function test_labels_returns_empty_string_for_empty_array(): void
    {
        $result = FocusTags::labels([]);

        $this->assertSame('', $result);
    }

    public function test_labels_skips_unknown_slugs(): void
    {
        $result = FocusTags::labels(['power', 'unknown', 'war']);

        $this->assertSame('Power, War', $result);
    }

    public function test_toggle_adds_a_tag(): void
    {
        $this->assertSame(['power', 'war'], FocusTags::toggle(['power'], 'war'));
    }

    public function test_toggle_removes_a_selected_tag(): void
    {
        $this->assertSame(['war'], FocusTags::toggle(['power', 'war'], 'power'));
    }

    public function test_toggle_ignores_a_fourth_tag(): void
    {
        $selection = ['power', 'war', 'trade'];

        $this->assertSame($selection, FocusTags::toggle($selection, 'religion'));
    }

    public function test_toggle_ignores_unknown_slugs(): void
    {
        $this->assertSame(['power'], FocusTags::toggle(['power'], 'not-a-tag'));
    }
}
