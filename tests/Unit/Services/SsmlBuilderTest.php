<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Avatar;
use App\Services\SsmlBuilder;
use PHPUnit\Framework\TestCase;

class SsmlBuilderTest extends TestCase
{
    private SsmlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SsmlBuilder();
    }

    private function makeAvatar(array $attrs = []): Avatar
    {
        $avatar = new Avatar();
        $avatar->forceFill(array_merge([
            'gender'         => 'male',
            'age'            => 35,
            'emotion_style'  => 'auto',
            'expressiveness' => 1.2,
            'speaking_speed' => 1.0,
        ], $attrs));
        return $avatar;
    }

    public function test_wraps_plain_script_in_speak_envelope(): void
    {
        $avatar = $this->makeAvatar();
        $ssml   = $this->builder->build('Hello world.', $avatar);

        $this->assertStringContainsString('<speak version="1.0"', $ssml);
        $this->assertStringContainsString('xmlns:mstts=', $ssml);
        $this->assertStringContainsString('<voice name="en-US-GuyNeural">', $ssml);
        $this->assertStringContainsString('<mstts:viseme type="FacialExpression"/>', $ssml);
        $this->assertStringContainsString('Hello world.', $ssml);
    }

    public function test_applies_prosody_rate(): void
    {
        $avatar = $this->makeAvatar(['speaking_speed' => 1.25]);
        $ssml   = $this->builder->build('Test.', $avatar);

        $this->assertStringContainsString('rate="1.25"', $ssml);
    }

    public function test_parses_emotion_tags_in_auto_mode(): void
    {
        $avatar = $this->makeAvatar(['emotion_style' => 'auto', 'expressiveness' => 1.5]);
        $ssml   = $this->builder->build('Intro. [serious]Heavy losses.[/serious] Done.', $avatar);

        $this->assertStringContainsString('<mstts:express-as style="serious" styledegree="1.50">', $ssml);
        $this->assertStringContainsString('Heavy losses.', $ssml);
        $this->assertStringContainsString('Intro.', $ssml);
    }

    public function test_wraps_entire_script_when_style_is_not_auto(): void
    {
        $avatar = $this->makeAvatar(['emotion_style' => 'cheerful', 'expressiveness' => 1.0]);
        $ssml   = $this->builder->build('[serious]Ignored.[/serious] Text.', $avatar);

        $this->assertStringContainsString('<mstts:express-as style="cheerful" styledegree="1.00">', $ssml);
        // The tag should not be processed — raw text inside
        $this->assertStringContainsString('[serious]', $ssml);
    }

    public function test_resolves_female_child_voice(): void
    {
        $avatar = $this->makeAvatar(['gender' => 'female', 'age' => 12]);
        $ssml   = $this->builder->build('Hi.', $avatar);

        $this->assertStringContainsString('<voice name="en-US-AnaNeural">', $ssml);
    }

    public function test_resolves_male_elder_voice(): void
    {
        $avatar = $this->makeAvatar(['gender' => 'male', 'age' => 70]);
        $ssml   = $this->builder->build('Hi.', $avatar);

        $this->assertStringContainsString('<voice name="en-US-RogerNeural">', $ssml);
    }

    public function test_escapes_special_xml_chars_in_plain_text(): void
    {
        $avatar = $this->makeAvatar();
        $ssml   = $this->builder->build('Caesar & Brutus <together>.', $avatar);

        $this->assertStringContainsString('Caesar &amp; Brutus &lt;together&gt;.', $ssml);
    }

    public function test_ignores_unknown_emotion_tags(): void
    {
        $avatar = $this->makeAvatar();
        $ssml   = $this->builder->build('[angry]Raw tag.[/angry]', $avatar);

        // Unknown tags pass through as plain text (XML-escaped)
        $this->assertStringNotContainsString('<mstts:express-as', $ssml);
    }
}
