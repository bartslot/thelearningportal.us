<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\AzureTtsException;
use App\Services\AzureTtsService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AzureTtsServiceTest extends TestCase
{
    public function test_throws_on_non_zero_exit_code(): void
    {
        Storage::fake('local');

        $service = new class extends AzureTtsService {
            protected function runPython(string $cmd): array
            {
                return [['Error: bad key'], 1];
            }
        };

        $this->expectException(AzureTtsException::class);
        $this->expectExceptionMessage('Error: bad key');

        $service->synthesize('<speak/>', 'lessons/1');
    }

    public function test_returns_paths_on_success(): void
    {
        Storage::fake('local');

        $service = new class extends AzureTtsService {
            protected function runPython(string $cmd): array
            {
                // Parse the actual output paths from the command and create fake files
                preg_match('/--audio-out\s+(\S+)/', $cmd, $audioMatch);
                preg_match('/--shapes-out\s+(\S+)/', $cmd, $shapesMatch);
                if (isset($audioMatch[1])) {
                    @mkdir(dirname($audioMatch[1]), 0755, true);
                    file_put_contents($audioMatch[1], 'fake-mp3-data');
                    file_put_contents($shapesMatch[1], '{"fps":60,"duration":1.0,"frames":[]}');
                }
                return [['OK: 0 frames'], 0];
            }
        };

        $result = $service->synthesize('<speak/>', 'lessons/1');

        $this->assertArrayHasKey('audio_3d_path', $result);
        $this->assertArrayHasKey('blendshapes_path', $result);
        $this->assertStringContainsString('lessons/1', $result['audio_3d_path']);
        $this->assertStringContainsString('lessons/1', $result['blendshapes_path']);
    }

    public function test_error_message_from_python_output_is_preserved(): void
    {
        Storage::fake('local');

        $service = new class extends AzureTtsService {
            protected function runPython(string $cmd): array
            {
                return [['Synthesis failed: 403 Unauthorized', 'Check your subscription key'], 1];
            }
        };

        try {
            $service->synthesize('<speak/>', 'lessons/99');
            $this->fail('Expected AzureTtsException was not thrown');
        } catch (AzureTtsException $e) {
            $this->assertStringContainsString('403 Unauthorized', $e->getMessage());
        }
    }
}
