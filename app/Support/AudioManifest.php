<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The soundtrack, in the shape the browser wants it.
 *
 * config/audio.php stores paths relative to the web root because that is how a human reads them;
 * the player needs absolute URLs. This turns one into the other, once, so the player and the
 * wizard preview are never handed two different soundtracks.
 */
final class AudioManifest
{
    /**
     * @return array{music: array{tracks: list<string>, volume: float, fade_ms: int}, sfx: array<string, string>}
     */
    public static function forBrowser(): array
    {
        return [
            'music' => [
                'tracks' => array_values(array_map(
                    static fn (string $path): string => asset($path),
                    (array) config('audio.background_music.tracks', []),
                )),
                'volume' => (float) config('audio.background_music.volume', 0.14),
                'fade_ms' => (int) config('audio.background_music.fade_ms', 4000),
            ],
            'sfx' => array_map(
                static fn (string $path): string => asset($path),
                (array) config('audio.sfx', []),
            ),
        ];
    }
}
