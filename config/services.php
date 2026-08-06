<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ── Local AI Services ─────────────────────────────────────────────────────

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.1:8b'),
    ],

    // ── Production AI APIs ────────────────────────────────────────────────────

    // ── Imagery policy ────────────────────────────────────────────────────────
    //
    // Scene backgrounds come from REAL historical imagery: our paintings corpus first, then
    // Wikimedia Commons. Generating them with an image model is expensive and, for history, worse:
    // a real painting carries provenance and an artist, an invented one carries neither.
    //
    // AI generation is therefore OFF by default and must be switched on deliberately.
    'imagery' => [
        'ai_generation' => (bool) env('AI_IMAGE_GENERATION', false),

        // Every sourced background is resized to fit this stage and squeezed under the byte cap.
        // Originals arrive at 3840px and 1.7-3 MB; sixteen of those is a lesson a school's wifi
        // cannot open. AVIF gets under 100 KB at a quality WebP needs twice the bytes to match.
        'max_bytes' => (int) env('BACKGROUND_MAX_BYTES', 100_000),
        'max_width' => (int) env('BACKGROUND_MAX_WIDTH', 1920),
        'max_height' => (int) env('BACKGROUND_MAX_HEIGHT', 1080),

        // Highest quality the search may spend, and how hard AOM works (0-10, lower is slower).
        'start_quality' => (int) env('BACKGROUND_START_QUALITY', 60),
        'encoder_speed' => (int) env('BACKGROUND_ENCODER_SPEED', 6),

        // AV1 film grain, drawn by the DECODER from ~100 bytes of parameters rather than baked
        // into the pixels. Presets 1-16 are different grain MODELS, not a weak-to-strong dial —
        // compare them with scripts/test-avif-grain-presets.sh before changing this. 0 disables.
        'grain_preset' => (int) env('BACKGROUND_GRAIN_PRESET', 1),
        'avifenc_path' => (string) env('AVIFENC_PATH', 'avifenc'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        // Dedicated key for image generation (gpt-image-1 needs a verified org); falls back to the main key.
        'image_api_key' => env('OPENAI_API_KEY_IMG', env('OPENAI_API_KEY')),
        'organization' => env('OPENAI_ORGANIZATION'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'json_format' => env('OPENAI_JSON_FORMAT', 'json_object'), // set empty to skip response_format (LM Studio)
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        'image_size' => env('OPENAI_IMAGE_SIZE', '1536x1024'),
        'scene_size' => env('OPENAI_SCENE_SIZE', env('OPENAI_IMAGE_SIZE', '1536x1024')),
        'skybox_size' => env('OPENAI_SKYBOX_SIZE', env('OPENAI_IMAGE_SIZE', '1536x1024')),
        'image_format' => env('OPENAI_IMAGE_FORMAT', 'webp'),       // png|jpeg|webp
        'image_compression' => (int) env('OPENAI_IMAGE_COMPRESSION', 60), // webp/jpeg quality: 100≈lossless/~1.5MB, 60≈high-res/~110KB, lower=smaller
        'image_stitch' => filter_var(env('OPENAI_IMAGE_STITCH', true), FILTER_VALIDATE_BOOLEAN), // 2-image stitched panorama
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 2048),
        'tts_model' => env('OPENAI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('OPENAI_TTS_VOICE', 'alloy'),
    ],

    'alerts' => [
        // Where low-credit warnings are sent.
        'email' => env('ADMIN_ALERT_EMAIL'),
        // Warn when fewer ElevenLabs characters remain than roughly two lessons' worth of narration.
        'elevenlabs_min_characters' => (int) env('ELEVENLABS_ALERT_MIN_CHARACTERS', 20000),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'base_url' => 'https://api.elevenlabs.io',
        // Narration voice for the Time-Map "read summary aloud" feature (George by default).
        'voice_id' => env('ELEVENLABS_VOICE_ID', 'JBFqnCBsd6RMkjVDRZzb'),
    ],

    'tts' => [
        // TEMPORARY global override for lesson narration (GenerateSceneAudio). Leave blank for the
        // normal per-avatar provider (ElevenLabs by default). Set to 'azure' (our backup) to route
        // all narration off ElevenLabs without editing avatars — revert by clearing the env var.
        // When the override is a non-ElevenLabs provider, the avatar's ElevenLabs voice_id is not
        // valid for it, so override_voice supplies a provider-native voice (blank → provider default,
        // e.g. Azure's en-US-GuyNeural).
        'provider_override' => env('TTS_PROVIDER_OVERRIDE'),
        'provider_override_voice' => env('TTS_PROVIDER_OVERRIDE_VOICE'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    // ── Image search (lesson slideshow backgrounds) ───────────────────────────

    'europeana' => [
        'key' => env('EUROPEANA_API_KEY'),
        'base_url' => env('EUROPEANA_BASE_URL', 'https://api.europeana.eu/record/v2'),
        'timeout' => (int) env('EUROPEANA_TIMEOUT', 30),
    ],

    // ── fal.ai (image upscaling for skybox panoramas) ─────────────────────────

    'falai' => [
        'api_key' => env('FAL_AI_KEY'),
        // Image generation (scene + skybox)
        'image_model' => env('FAL_IMAGE_MODEL', 'fal-ai/flux/schnell'),
        'scene_width' => (int) env('FAL_SCENE_WIDTH', 1024),
        'scene_height' => (int) env('FAL_SCENE_HEIGHT', 512),
        'skybox_width' => (int) env('FAL_SKYBOX_WIDTH', 1024),
        'skybox_height' => (int) env('FAL_SKYBOX_HEIGHT', 512),
        'steps' => (int) env('FAL_STEPS', 4),
        'timeout' => (int) env('FAL_TIMEOUT', 90),
        // Upscaling (clarity-upscaler) — OFF by default, uses local Upscayl instead
        'upscale_enabled' => filter_var(env('FAL_UPSCALE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'upscale_model' => env('FAL_UPSCALE_MODEL', 'fal-ai/clarity-upscaler'),
        'upscale_factor' => (int) env('FAL_UPSCALE_FACTOR', 2),
        'enhance_model' => env('FAL_ENHANCE_MODEL', 'fal-ai/clarity-upscaler'),
        'enhance_factor' => (int) env('FAL_ENHANCE_FACTOR', 4),
        'connect_timeout' => (int) env('FAL_UPSCALE_CONNECT_TIMEOUT', 10),
    ],

    // ── LM Studio (local OpenAI-compatible LLM) ───────────────────────────────
    // ── ffmpeg (video transcoding: uploads become 720p H.264) ────────────────
    // Present on both machines but at different paths — Homebrew puts it in /opt/homebrew/bin,
    // SiteGround in /usr/local/bin — so it is resolved from PATH by default and pinnable per host.
    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', 'ffmpeg'),
        'probe_path' => env('FFPROBE_PATH', 'ffprobe'),
    ],

    // ── Upscayl local CLI (replaces fal.ai upscaling in dev) ─────────────────
    'upscayl' => [
        'enabled' => filter_var(env('UPSCAYL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'bin' => env('UPSCAYL_BIN', '/Applications/Upscayl.app/Contents/Resources/bin/upscayl-bin'),
        // Relative to the bin's dir: upscayl-bin prepends its own exe dir to -m, so an absolute
        // path doubles (…/bin//Applications/…). ../models resolves to Resources/models.
        'model_path' => env('UPSCAYL_MODEL_PATH', '../models'),
        // Must be a model this Upscayl build actually ships (no realesrgan-x4plus here).
        'model' => env('UPSCAYL_MODEL', 'upscayl-standard-4x'),
    ],

    // ── Animation tooling ─────────────────────────────────────────────────────

    // ── Azure Speech Service (3D avatar lip sync) ─────────────────────────────

    'azure_speech' => [
        'key' => env('AZURE_SPEECH_KEY'),
        'region' => env('AZURE_SPEECH_REGION', 'eastus'),
    ],

    // ── Pocket TTS (local voice cloning) ──────────────────────────────────────

    'pocket_tts' => [
        'url' => env('POCKET_TTS_URL', 'http://localhost:8001'),
        'hf_token' => env('HF_TOKEN'),
    ],

    // Self-hosted Piper TTS (Oracle Always-Free box) — cheap Dutch narration for draft/preview.
    'piper' => [
        'url' => env('PIPER_TTS_URL'),
        'voice' => env('PIPER_TTS_VOICE', 'nl_NL-pim-medium'),
        'token' => env('PIPER_TTS_TOKEN'),
    ],

    // ── WorldLabs Marble (3D Gaussian-splat world from image) ─────────────────

    'worldlabs' => [
        'api_key' => env('WORLD_LABS_API_KEY'),
        'enabled' => filter_var(env('WORLD_LABS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // ── Cloudinary (hosts uploaded + AI-generated imagery off our own storage) ─
    'cloudinary' => [
        'url' => env('CLOUDINARY_URL'),
    ],

    // ── WordPress, read as a headless CMS ─────────────────────────────────────
    // The marketing site at thelearningportal.us runs WordPress with the public REST API open, so
    // published posts appear on this subdomain with no credentials and nothing to write back.
    // Point WORDPRESS_URL somewhere else (or blank it) to change or disable the feed.
    'wordpress' => [
        'url' => env('WORDPRESS_URL', 'https://thelearningportal.us'),
    ],

];
