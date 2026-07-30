<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns a teacher-pasted link (or a full <iframe> embed snippet) into a safe, embeddable source.
 * Supports Sketchfab (3D) and YouTube / Vimeo / generic-iframe video (Klokhuis etc.).
 *
 * We only ever KEEP the video element (an iframe src) — never arbitrary pasted HTML/scripts.
 */
class EmbedParser
{
    /**
     * Sketchfab 3D model → ['kind' => 'sketchfab', 'id' => <32hex>, 'src' => embed url] or null.
     */
    public function sketchfab(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        // A full <iframe> snippet: pull the src first, then the id out of it.
        if ($src = $this->iframeSrc($input)) {
            $input = $src;
        }
        // Sketchfab model ids are 32 hex chars (…/3d-models/name-<id>, …/models/<id>/embed).
        if (! preg_match('/([0-9a-f]{32})/i', $input, $m)) {
            return null;
        }
        $id = strtolower($m[1]);

        return [
            'kind' => 'sketchfab',
            'id' => $id,
            'src' => "https://sketchfab.com/models/{$id}/embed",
        ];
    }

    /**
     * The viewer URL for a Sketchfab model, built from the teacher's choices.
     *
     * Defaults matter here: a model dropped on a slide should arrive with its studio backdrop
     * GONE (transparent=1), because the point is the object, not the scene someone else lit it in.
     *
     * @param  array{interact?:bool,autospin?:bool,bg?:string}  $opts  bg: 'none' | 'glass' | '#rrggbb'
     */
    public function sketchfabSrc(string $id, array $opts = []): string
    {
        $interact = (bool) ($opts['interact'] ?? true);
        $autospin = (bool) ($opts['autospin'] ?? true);
        $bg = (string) ($opts['bg'] ?? 'none');
        $solid = preg_match('/^#[0-9a-fA-F]{6}$/', $bg) === 1;

        $params = [
            'autostart' => 1,
            // Sketchfab reads a fraction of a turn per second; 0 stops it dead.
            'autospin' => $autospin ? 0.2 : 0,
            // Interaction is the orbit controls plus the scroll wheel. With it off the model is a
            // moving picture, which is what you want behind narration.
            'ui_controls' => $interact ? 1 : 0,
            'scrollwheel' => $interact ? 1 : 0,
            'ui_infos' => 0, 'ui_hint' => 0, 'ui_ar' => 0, 'ui_help' => 0,
            'ui_settings' => 0, 'ui_vr' => 0, 'ui_fullscreen' => 0,
            'ui_annotations' => 0, 'ui_stop' => 0, 'ui_watermark' => 0, 'ui_watermark_link' => 0,
            'dnt' => 1,
        ];
        // 'none' and 'glass' both want the model cut out; glass adds its frosting on OUR side of
        // the iframe, where we can blur what is behind it.
        if (! $solid) {
            $params['transparent'] = 1;
        } else {
            $params['ui_color'] = ltrim($bg, '#');
        }

        return "https://sketchfab.com/models/{$id}/embed?".http_build_query($params);
    }

    /**
     * Video → ['kind' => 'video', 'provider' => youtube|vimeo|other, 'id' => ?, 'src' => embed base url]
     * or null. `src` is the bare embed URL; per-scene settings (autoplay/start/end/controls) are
     * layered on at render time by embedVideoSrc().
     */
    public function video(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $fromIframe = $this->iframeSrc($input);
        $url = $fromIframe ?: $input;

        // YouTube — watch?v=, youtu.be/, /embed/, /shorts/ ; 11-char id.
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|v/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})~i', $url, $m)) {
            return ['kind' => 'video', 'provider' => 'youtube', 'id' => $m[1], 'src' => "https://www.youtube.com/embed/{$m[1]}"];
        }
        // Vimeo — vimeo.com/<digits> or player.vimeo.com/video/<digits>.
        if (preg_match('~(?:player\.)?vimeo\.com/(?:video/)?(\d+)~i', $url, $m)) {
            return ['kind' => 'video', 'provider' => 'vimeo', 'id' => $m[1], 'src' => "https://player.vimeo.com/video/{$m[1]}"];
        }
        // Anything else (e.g. a Klokhuis embed snippet): keep the iframe src if we found one and it's
        // a real http(s) URL. A bare non-embed page URL is rejected — we can't guess its player.
        if ($fromIframe && preg_match('~^https?://~i', $fromIframe)) {
            return ['kind' => 'video', 'provider' => 'other', 'id' => null, 'src' => $fromIframe];
        }

        return null;
    }

    /**
     * Build the final video iframe src with the scene's playback settings applied per provider.
     *
     * @param  array{provider?:string,src?:string}  $embed
     * @param  array{autoplay?:bool,start?:int,end?:int,controls?:bool}  $opts
     */
    public function embedVideoSrc(array $embed, array $opts = []): string
    {
        $src = (string) ($embed['src'] ?? '');
        if ($src === '') {
            return '';
        }
        $autoplay = ! empty($opts['autoplay']);
        $controls = array_key_exists('controls', $opts) ? (bool) $opts['controls'] : true;
        $start = max(0, (int) ($opts['start'] ?? 0));
        $end = max(0, (int) ($opts['end'] ?? 0));

        $params = [];
        $hash = '';
        switch ($embed['provider'] ?? 'other') {
            case 'youtube':
                $params['autoplay'] = $autoplay ? 1 : 0;
                $params['mute'] = $autoplay ? 1 : 0;   // browsers block un-muted autoplay
                $params['controls'] = $controls ? 1 : 0;
                $params['rel'] = 0;
                $params['playsinline'] = 1;
                $params['modestbranding'] = 1;
                if ($start > 0) {
                    $params['start'] = $start;
                }
                if ($end > $start) {
                    $params['end'] = $end;
                }
                break;
            case 'vimeo':
                $params['autoplay'] = $autoplay ? 1 : 0;
                $params['muted'] = $autoplay ? 1 : 0;
                $params['controls'] = $controls ? 1 : 0;
                $params['playsinline'] = 1;
                if ($start > 0) {
                    $hash = '#t='.$start.'s';   // Vimeo takes start via the fragment
                }
                break;
            default:
                if ($autoplay) {
                    $params['autoplay'] = 1;
                    $params['muted'] = 1;
                }
        }
        $sep = str_contains($src, '?') ? '&' : '?';

        return $params ? $src.$sep.http_build_query($params).$hash : $src.$hash;
    }

    /** Pull the src="…" out of a pasted <iframe …> snippet, or null if the input isn't one. */
    private function iframeSrc(string $input): ?string
    {
        if (preg_match('/<iframe[^>]*\ssrc=["\']([^"\']+)["\']/i', $input, $m)) {
            return html_entity_decode($m[1]);
        }

        return null;
    }
}
