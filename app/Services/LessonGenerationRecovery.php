<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class LessonGenerationRecovery
{
    public function purgeQueuedJobs(Lesson $lesson): int
    {
        $sceneIds = $lesson->scenes()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $removed = $this->purgePayloadTable(
            (string) config('queue.connections.database.table', 'jobs'),
            'id',
            $lesson->id,
            $sceneIds,
        );

        $removed += $this->purgePayloadTable('failed_jobs', 'id', $lesson->id, $sceneIds);

        if (Schema::hasTable('job_batches')) {
            $removed += DB::table('job_batches')
                ->where('name', "lesson:{$lesson->id}:scenes")
                ->delete();
        }

        return $removed;
    }

    public function abandon(Lesson $lesson): int
    {
        $lessonId = $lesson->id;

        $removed = DB::transaction(function () use ($lesson): int {
            $removedJobs = $this->purgeQueuedJobs($lesson);
            $lesson->forceDelete();

            return $removedJobs;
        });

        foreach (['public', 'local'] as $disk) {
            try {
                Storage::disk($disk)->deleteDirectory("lessons/{$lessonId}");
            } catch (\Throwable) {
                // The database cleanup is authoritative; missing media must never block recovery.
            }
        }

        return $removed;
    }

    /**
     * @param  list<int>  $sceneIds
     */
    private function purgePayloadTable(
        string $table,
        string $key,
        int $lessonId,
        array $sceneIds,
    ): int {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $ids = DB::table($table)
            ->get([$key, 'payload'])
            ->filter(fn ($row): bool => $this->payloadTargetsLesson(
                (string) $row->payload,
                $lessonId,
                $sceneIds,
            ))
            ->pluck($key)
            ->all();

        if ($ids === []) {
            return 0;
        }

        return DB::table($table)->whereIn($key, $ids)->delete();
    }

    /**
     * @param  list<int>  $sceneIds
     */
    private function payloadTargetsLesson(string $payload, int $lessonId, array $sceneIds): bool
    {
        $decoded = json_decode($payload, true);
        $command = is_array($decoded)
            ? (string) data_get($decoded, 'data.command', '')
            : '';

        if ($command === '') {
            return false;
        }

        if ($this->serializedIntegerMatches($command, 'lessonId', $lessonId)) {
            return true;
        }

        foreach ($sceneIds as $sceneId) {
            if ($this->serializedIntegerMatches($command, 'sceneId', $sceneId)) {
                return true;
            }
        }

        return false;
    }

    private function serializedIntegerMatches(string $command, string $property, int $value): bool
    {
        return preg_match(
            '/s:\d+:"'.preg_quote($property, '/').'";i:'.preg_quote((string) $value, '/').';/',
            $command,
        ) === 1;
    }
}
