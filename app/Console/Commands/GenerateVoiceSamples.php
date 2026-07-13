<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Avatar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Pre-generates one audition sample per edge-tts voice into public/voices/edge/.
 *
 * The Avatar Studio plays these on click — no runtime TTS calls, no waiting.
 * The files are committed (≈100 KB each) and served as static assets, so the
 * host CDN caches them. Idempotent: existing files are skipped unless --force.
 */
class GenerateVoiceSamples extends Command
{
    protected $signature = 'voices:samples {--force : Regenerate even when the file exists}';

    protected $description = 'Pre-generate audition samples for every edge-tts voice (public/voices/edge)';

    /** Sample line per language — the voice auditions in its OWN language. */
    private const SAMPLE_TEXT = [
        'nl' => 'Welkom, leerlingen. Vandaag beginnen we aan een bijzondere reis door de geschiedenis.',
        'en' => 'Welcome, students. Today we embark on a remarkable journey through history.',
        'es' => 'Bienvenidos, estudiantes. Hoy comenzamos un viaje extraordinario por la historia.',
    ];

    public function handle(): int
    {
        $dir = public_path('voices/edge');
        File::ensureDirectoryExists($dir);

        $made = 0;
        $skipped = 0;
        $failed = 0;
        foreach (array_keys(Avatar::edgeTtsVoices()) as $voiceId) {
            $out = "{$dir}/{$voiceId}.mp3";
            if (! $this->option('force') && is_file($out)) {
                $skipped++;

                continue;
            }

            $lang = substr($voiceId, 0, 2);
            $text = self::SAMPLE_TEXT[$lang] ?? self::SAMPLE_TEXT['en'];

            $process = new Process(['edge-tts', '--voice', $voiceId, '--text', $text, '--write-media', $out]);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful() && is_file($out) && filesize($out) > 1000) {
                $made++;
                $this->line("<info>✓</info> {$voiceId}");
            } else {
                $failed++;
                @unlink($out);
                $this->warn("✗ {$voiceId}: ".trim($process->getErrorOutput() ?: 'no output'));
            }
        }

        $this->info("Done: {$made} generated, {$skipped} skipped, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
