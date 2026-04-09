<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AvatarService
{
    private function lessonDisk()
    {
        return Storage::disk('public');
    }

    /**
     * Generate an avatar video from a portrait image and audio file.
     * Returns the storage path to the generated MP4 or null on failure.
     */
    public function generateVideo(string $audioPath, string $portraitPath, string $lessonId): ?string
    {
        $videoPath = "lessons/{$lessonId}/avatar.mp4";

        $sadtalkerDir = config('services.sadtalker.dir');
        $falKey       = config('services.fal.api_key');

        // Nothing configured at all — skip silently, this is a normal dev state
        if (! $sadtalkerDir && ! $falKey) {
            Log::info("AvatarService: no video provider configured (set SADTALKER_DIR or FAL_AI_KEY) — skipping lesson {$lessonId}");
            return null;
        }

        // Try local SadTalker CLI → fallback to fal.ai
        $videoContent = $this->trySadTalkerLocal($audioPath, $portraitPath)
            ?? $this->tryFalAi($audioPath, $portraitPath);

        if ($videoContent === null) {
            Log::error("AvatarService: all providers failed for lesson {$lessonId}");
            return null;
        }

        $this->lessonDisk()->put($videoPath, $videoContent);
        Log::info("AvatarService: video saved at {$videoPath}");
        return $videoPath;
    }

    /**
     * Download a portrait image from a URL, resize if needed, and store it.
     * Returns the storage path or null.
     */
    public function downloadPortrait(string $imageUrl, string $lessonId): ?string
    {
        try {
            $response = Http::timeout(30)->get($imageUrl);

            if ($response->successful()) {
                $imageData = $this->resizePortrait($response->body());
                $portraitPath = "lessons/{$lessonId}/portrait.jpg";
                $this->lessonDisk()->put($portraitPath, $imageData);
                return $portraitPath;
            }
        } catch (\Exception $e) {
            Log::error("AvatarService::downloadPortrait failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Copy the default professor portrait to lesson storage as a fallback.
     * Returns the storage path or null if the fallback file is missing.
     */
    public function useDefaultPortrait(string $lessonId): ?string
    {
        $fallbackFile = public_path('assets/professor.webp');

        if (! file_exists($fallbackFile)) {
            Log::warning("AvatarService::useDefaultPortrait: fallback file not found at {$fallbackFile}");
            return null;
        }

        $imageData = $this->resizePortrait(file_get_contents($fallbackFile));
        $portraitPath = "lessons/{$lessonId}/portrait.jpg";
        $this->lessonDisk()->put($portraitPath, $imageData);

        return $portraitPath;
    }

    /**
     * Public wrapper around resizePortrait for use outside this class.
     */
    public function resizePortraitPublic(string $imageData, int $maxDim = 512): string
    {
        return $this->resizePortrait($imageData, $maxDim);
    }

    /**
     * Resize an image to at most $maxDim × $maxDim using GD, and output as JPEG.
     * Falls back to returning the original bytes if GD is unavailable or parsing fails.
     */
    private function resizePortrait(string $imageData, int $maxDim = 512): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $imageData;
        }

        $src = @imagecreatefromstring($imageData);

        if (! $src) {
            return $imageData;
        }

        $origW = imagesx($src);
        $origH = imagesy($src);

        if ($origW <= $maxDim && $origH <= $maxDim) {
            // Already small enough — just re-encode as JPEG to normalise format
            ob_start();
            imagejpeg($src, null, 88);
            $output = ob_get_clean();
            imagedestroy($src);
            return $output ?: $imageData;
        }

        $ratio = min($maxDim / $origW, $maxDim / $origH);
        $newW  = (int) round($origW * $ratio);
        $newH  = (int) round($origH * $ratio);

        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        ob_start();
        imagejpeg($dst, null, 88);
        $output = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $output ?: $imageData;
    }

    // ── Local SadTalker (CLI inference.py) ───────────────────────────────────

    private function trySadTalkerLocal(string $audioPath, string $portraitPath): ?string
    {
        $sadtalkerDir = config('services.sadtalker.dir');
        $python       = config('services.sadtalker.python');

        if (! is_dir($sadtalkerDir) || ! is_file($python)) {
            Log::info('AvatarService: SadTalker not configured (set SADTALKER_DIR / SADTALKER_PYTHON)');
            return null;
        }

        try {
            $disk       = $this->lessonDisk();
            $audioAbs   = $disk->path($audioPath);
            $portraitAbs = $disk->path($portraitPath);

            if (! $disk->exists($audioPath) || ! $disk->exists($portraitPath)) return null;

            $outputDir = sys_get_temp_dir() . '/sadtalker_' . uniqid();
            mkdir($outputDir, 0755, true);

            $cmd = implode(' ', array_map('escapeshellarg', [
                $python,
                $sadtalkerDir . '/inference.py',
                '--driven_audio',  $audioAbs,
                '--source_image',  $portraitAbs,
                '--result_dir',    $outputDir,
                '--still',
                '--preprocess',    'crop',
                '--enhancer',      'gfpgan',
            ]));

            Log::info("AvatarService: running SadTalker CLI: {$cmd}");

            $output   = [];
            $exitCode = 0;
            exec("cd " . escapeshellarg($sadtalkerDir) . " && {$cmd} 2>&1", $output, $exitCode);

            if ($exitCode !== 0) {
                Log::warning('AvatarService: SadTalker CLI failed', ['output' => implode("\n", array_slice($output, -20))]);
                return null;
            }

            // Find the generated mp4 in the timestamped subdirectory
            $mp4Files = glob($outputDir . '/*/*.mp4') ?: glob($outputDir . '/*.mp4');
            if (empty($mp4Files)) {
                Log::warning('AvatarService: SadTalker produced no mp4', ['output_dir' => $outputDir]);
                return null;
            }

            $videoContent = file_get_contents($mp4Files[0]);
            array_map('unlink', glob($outputDir . '/*/*') ?: []);
            array_map('unlink', glob($outputDir . '/*') ?: []);
            @rmdir($outputDir);

            return $videoContent ?: null;

        } catch (\Exception $e) {
            Log::warning('AvatarService: SadTalker CLI exception: ' . $e->getMessage());
        }

        return null;
    }

    // ── fal.ai SadTalker (cloud fallback) ────────────────────────────────────

    private function tryFalAi(string $audioPath, string $portraitPath): ?string
    {
        $apiKey = config('services.fal.api_key');
        if (! $apiKey) return null;

        try {
            // Upload files to fal.ai storage first
            $audioUrl   = $this->uploadToFal($audioPath, $apiKey);
            $portraitUrl = $this->uploadToFal($portraitPath, $apiKey);

            if (! $audioUrl || ! $portraitUrl) return null;

            // Run SadTalker via fal.ai
            $response = Http::timeout(300)
                ->withHeaders(['Authorization' => "Key {$apiKey}"])
                ->post('https://queue.fal.run/fal-ai/sadtalker', [
                    'source_image_url' => $portraitUrl,
                    'driven_audio_url' => $audioUrl,
                    'preprocess'       => 'crop',
                    'still_mode'       => false,
                    'use_enhancer'     => true,
                ]);

            if ($response->successful()) {
                $videoUrl = $response->json('video.url');
                if ($videoUrl) {
                    $video = Http::timeout(60)->get($videoUrl);
                    if ($video->successful()) return $video->body();
                }
            }

            Log::warning('AvatarService: fal.ai responded with error', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
        } catch (\Exception $e) {
            Log::warning('AvatarService::tryFalAi failed: ' . $e->getMessage());
        }

        return null;
    }

    private function uploadToFal(string $storagePath, string $apiKey): ?string
    {
        $content     = $this->lessonDisk()->get($storagePath);
        $filename    = basename($storagePath);
        $contentType = $this->detectContentType($storagePath, $filename);

        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => "Key {$apiKey}",
                'Content-Type'  => $contentType,
            ])
            ->withBody($content, $contentType)
            ->post('https://rest.alpha.fal.ai/storage/upload/file?file_name=' . urlencode($filename));

        return $response->successful() ? $response->json('url') : null;
    }

    private function detectContentType(string $storagePath, string $filename): string
    {
        $publicDisk = $this->lessonDisk();

        if ($publicDisk->exists($storagePath)) {
            try {
                $mime = $publicDisk->mimeType($storagePath);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            } catch (\Throwable) {
                // Fall through to extension-based detection.
            }
        }

        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
