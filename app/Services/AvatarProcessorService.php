<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Exception;

class AvatarProcessorService
{
    private string $projectRoot = '/Users/bartslot/Projects/thelearningportal.us';
    private string $avatarsDir = 'public/avatars';
    private string $scriptPath = 'process_avatar.sh';

    /**
     * Process a Mixamo FBX file and create a GLB avatar with shape keys
     *
     * @param string $fbxPath Path to the Mixamo FBX file
     * @param string $characterName Name of the character (e.g., "Julius Caesar")
     * @param int $characterAge Age of the character
     * @param string $characterGender Gender (M, F, Other)
     *
     * @return array Avatar data with id, path, and metadata
     * @throws Exception
     */
    public function processAvatar(
        string $fbxPath,
        string $characterName,
        int $characterAge,
        string $characterGender
    ): array {
        // Validate inputs
        if (!file_exists($fbxPath)) {
            throw new Exception("FBX file not found: $fbxPath");
        }

        if (!in_array($characterGender, ['M', 'F', 'Other'])) {
            throw new Exception("Invalid gender. Must be M, F, or Other");
        }

        if ($characterAge < 0 || $characterAge > 150) {
            throw new Exception("Invalid age. Must be between 0 and 150");
        }

        $scriptPath = base_path($this->scriptPath);

        if (!file_exists($scriptPath)) {
            throw new Exception("Avatar processor script not found: $scriptPath");
        }

        // Run the Blender processor script
        try {
            $result = Process::timeout(300) // 5 minute timeout
                ->run("bash '{$scriptPath}' '{$fbxPath}' '{$characterName}' {$characterAge} '{$characterGender}'");

            if (!$result->successful()) {
                throw new Exception("Avatar processing failed:\n" . $result->errorOutput());
            }

            // Parse output to extract avatar ID
            $output = $result->output();
            preg_match('/ID: ([a-f0-9\-]+)/i', $output, $matches);

            if (empty($matches[1])) {
                throw new Exception("Could not extract avatar ID from processor output");
            }

            $avatarId = $matches[1];
            $avatarPath = "{$this->avatarsDir}/{$avatarId}";
            $glbPath = "{$avatarPath}/character.glb";
            $metadataPath = "{$avatarPath}/metadata.json";

            // Load metadata
            $metadata = [];
            if (file_exists(base_path($metadataPath))) {
                $metadata = json_decode(file_get_contents(base_path($metadataPath)), true);
            }

            return [
                'id' => $avatarId,
                'name' => $characterName,
                'age' => $characterAge,
                'gender' => $characterGender,
                'glb_path' => $glbPath,
                'metadata_path' => $metadataPath,
                'shape_keys_count' => 52,
                'output' => $output,
                'metadata' => $metadata,
            ];
        } catch (Exception $e) {
            throw new Exception("Avatar processing error: " . $e->getMessage());
        }
    }

    /**
     * Get metadata for an existing avatar
     *
     * @param string $avatarId
     *
     * @return array|null
     */
    public function getAvatarMetadata(string $avatarId): ?array
    {
        $metadataPath = base_path("{$this->avatarsDir}/{$avatarId}/metadata.json");

        if (!file_exists($metadataPath)) {
            return null;
        }

        return json_decode(file_get_contents($metadataPath), true);
    }

    /**
     * List all available avatars
     *
     * @return array
     */
    public function listAvatars(): array
    {
        $avatarsPath = base_path($this->avatarsDir);

        if (!is_dir($avatarsPath)) {
            return [];
        }

        $avatars = [];
        foreach (scandir($avatarsPath) as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $metadata = $this->getAvatarMetadata($dir);
            if ($metadata) {
                $avatars[] = array_merge(['id' => $dir], $metadata);
            }
        }

        return $avatars;
    }

    /**
     * Delete an avatar and its files
     *
     * @param string $avatarId
     *
     * @return bool
     */
    public function deleteAvatar(string $avatarId): bool
    {
        $avatarPath = base_path("{$this->avatarsDir}/{$avatarId}");

        if (!is_dir($avatarPath)) {
            return false;
        }

        // Delete directory and contents
        $files = array_diff(scandir($avatarPath), ['.', '..']);
        foreach ($files as $file) {
            $filePath = "{$avatarPath}/{$file}";
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }

        return rmdir($avatarPath);
    }
}
