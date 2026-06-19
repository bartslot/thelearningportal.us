<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AvatarService
{
    private function lessonDisk()
    {
        return Storage::disk('public');
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
     * Public wrapper around resizePortrait — used by Avatar Studio's image upload.
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

}
