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
            private function extractArg(string $cmd, string $flag): ?string
            {
                $pattern = '/(?:^|\s)' . preg_quote($flag, '/') . '\s+(\'[^\']*\'|\S+)/';
                if (preg_match($pattern, $cmd, $match) !== 1) {
                    return null;
                }

                $value = $match[1];

                // runPython() receives shell-escaped args from escapeshellarg(),
                // so strip only wrapping quotes before using as filesystem paths.
                if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
                    $value = substr($value, 1, -1);
                }

                return $value;
            }

            protected function runPython(string $cmd): array
            {
                // Parse the actual output paths from the command and create fake files
                $audioPath = $this->extractArg($cmd, '--audio-out');
                $shapesPath = $this->extractArg($cmd, '--shapes-out');

                if (is_string($audioPath) && is_string($shapesPath)) {
                    @mkdir(dirname($audioPath), 0755, true);
                    file_put_contents($audioPath, 'fake-mp3-data');
                    file_put_contents($shapesPath, '{"fps":60,"duration":1.0,"frames":[]}');
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
