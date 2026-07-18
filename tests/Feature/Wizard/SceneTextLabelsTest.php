<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Teacher text labels ([T] tool) — the sceneTextsChanged payload comes straight from the
 * browser, so every field must be validated server-side: font/size whitelists, map-anchor
 * coordinates, and hard caps on count and length.
 */
class SceneTextLabelsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;
    private Scene $scene;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);
        $this->scene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'status' => 'ready',
        ]);
    }

    private function saveTexts(array $texts): array
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->dispatch('sceneTextsChanged', sceneId: $this->scene->id, texts: $texts);

        return $this->scene->fresh()->config['texts'] ?? [];
    }

    public function test_valid_font_size_and_map_anchor_persist(): void
    {
        $texts = $this->saveTexts([[
            'id' => 'txt_a', 'text' => 'Rome', 'x' => 40, 'y' => 40,
            'font' => 'cinzel', 'size' => 'xl', 'anchor' => 'map', 'lng' => 12.5, 'lat' => 41.9,
        ]]);

        $this->assertSame('cinzel', $texts[0]['font']);
        $this->assertSame('xl', $texts[0]['size']);
        $this->assertSame('map', $texts[0]['anchor']);
        $this->assertEqualsWithDelta(12.5, $texts[0]['lng'], 0.001);
        $this->assertEqualsWithDelta(41.9, $texts[0]['lat'], 0.001);
    }

    public function test_unknown_font_and_size_fall_back_to_defaults(): void
    {
        $texts = $this->saveTexts([[
            'id' => 'txt_a', 'text' => 'Rome', 'x' => 40, 'y' => 40,
            'font' => 'comic-sans"><script>', 'size' => 'xxl',
        ]]);

        $this->assertSame('sans', $texts[0]['font']);
        $this->assertSame('md', $texts[0]['size']);
    }

    public function test_map_anchor_coordinates_are_clamped_to_the_valid_range(): void
    {
        $texts = $this->saveTexts([[
            'id' => 'txt_a', 'text' => 'Rome', 'x' => 40, 'y' => 40,
            'anchor' => 'map', 'lng' => 720, 'lat' => -3000,
        ]]);

        $this->assertEqualsWithDelta(180.0, $texts[0]['lng'], 0.001);
        $this->assertEqualsWithDelta(-85.0, $texts[0]['lat'], 0.001);
    }

    public function test_map_anchor_without_coordinates_falls_back_to_screen(): void
    {
        $texts = $this->saveTexts([
            ['id' => 'a', 'text' => 'No coords', 'x' => 10, 'y' => 10, 'anchor' => 'map'],
            ['id' => 'b', 'text' => 'Bad coords', 'x' => 10, 'y' => 10, 'anchor' => 'map', 'lng' => 'east', 'lat' => []],
        ]);

        foreach ($texts as $t) {
            $this->assertSame('screen', $t['anchor']);
            $this->assertNull($t['lng']);
            $this->assertNull($t['lat']);
        }
    }

    public function test_screen_anchored_labels_never_keep_stray_coordinates(): void
    {
        $texts = $this->saveTexts([[
            'id' => 'a', 'text' => 'Screen label', 'x' => 10, 'y' => 10,
            'anchor' => 'screen', 'lng' => 12.5, 'lat' => 41.9,
        ]]);

        $this->assertNull($texts[0]['lng']);
        $this->assertNull($texts[0]['lat']);
    }

    public function test_text_count_and_length_are_capped(): void
    {
        $payload = array_map(fn (int $i) => [
            'id' => "txt_{$i}", 'text' => str_repeat('x', 5000), 'x' => 10, 'y' => 10,
        ], range(0, 19));

        $texts = $this->saveTexts($payload);

        $this->assertCount(12, $texts, 'labels cap at 12 per scene');
        $this->assertSame(600, mb_strlen($texts[0]['text']), 'label text caps at 600 chars');
    }

    public function test_client_supplied_ids_are_length_capped(): void
    {
        $texts = $this->saveTexts([[
            'id' => str_repeat('A', 100000), 'text' => 'Rome', 'x' => 10, 'y' => 10,
        ]]);

        $this->assertLessThanOrEqual(64, mb_strlen($texts[0]['id']),
            'a hostile client must not be able to bloat scene config through the id field');
    }

    public function test_format_panel_follows_text_selection_and_returns_to_scene_settings(): void
    {
        $this->scene->update(['config' => [
            'texts' => [['id' => 'txt_rome', 'text' => 'Rome', 'x' => 40, 'y' => 40]],
        ]]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->dispatch('scene:selection-changed', objectId: 'txt_rome')
            ->assertSet('activeTextId', 'txt_rome')
            ->assertSee('Edit content directly on the canvas.')
            ->assertSeeHtml("new CustomEvent('inspector-open')")
            ->dispatch('scene:selection-changed', objectId: '__bg__')
            ->assertSet('activeTextId', null)
            ->assertSee('Scene details')
            ->assertSeeHtml('wire:ignore.self');
    }

    public function test_format_panel_updates_a_selected_text_without_losing_other_properties(): void
    {
        $this->scene->update(['config' => [
            'texts' => [[
                'id' => 'txt_rome', 'text' => 'Rome', 'x' => 40, 'y' => 40,
                'font' => 'sans', 'size' => 'md', 'anchor' => 'screen',
            ]],
        ]]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->dispatch('scene:selection-changed', objectId: 'txt_rome')
            ->call('updateSceneText', 'txt_rome', 'font', 'cinzel')
            ->call('updateSceneText', 'txt_rome', 'w', 60);

        $text = $this->scene->fresh()->config['texts'][0];
        $this->assertSame('Rome', $text['text']);
        $this->assertSame('cinzel', $text['font']);
        $this->assertEquals(60.0, $text['w']);
    }

    public function test_saving_a_newly_selected_text_opens_its_format_controls(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->dispatch('sceneTextsChanged', sceneId: $this->scene->id, selectedTextId: 'txt_rome', texts: [
                ['id' => 'txt_rome', 'text' => 'Rome', 'x' => 40, 'y' => 40],
            ])
            ->assertSet('activeTextId', 'txt_rome')
            ->assertSee('Edit content directly on the canvas.');
    }
}
