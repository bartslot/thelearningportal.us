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

    'comfyui' => [
        'url' => env('COMFYUI_URL', 'http://localhost:8188'),
        'dir' => env('COMFYUI_DIR', ($_SERVER['HOME'] ?? '').'/ComfyUI'),
        'python' => env('COMFYUI_PYTHON', 'python'),
        'checkpoint' => env('COMFYUI_CHECKPOINT', 'flux1-schnell-Q5_K_S.gguf'),
        'clip1' => env('COMFYUI_CLIP1', 'clip_l.safetensors'),
        'clip2' => env('COMFYUI_CLIP2', 't5xxl_fp8_e4m3fn.safetensors'),
        'vae' => env('COMFYUI_VAE', 'ae.safetensors'),
        'sampler' => env('COMFYUI_SAMPLER', 'euler'),
        'scheduler' => env('COMFYUI_SCHEDULER', 'simple'),
        'steps' => (int) env('COMFYUI_STEPS', 4),
        'cfg' => (float) env('COMFYUI_CFG', 1.0),
        'scene_width' => (int) env('COMFYUI_SCENE_WIDTH', 1024),
        'scene_height' => (int) env('COMFYUI_SCENE_HEIGHT', 512),
        'skybox_width' => (int) env('COMFYUI_SKYBOX_WIDTH', 2048),
        'skybox_height' => (int) env('COMFYUI_SKYBOX_HEIGHT', 1024),
        'timeout' => (int) env('COMFYUI_TIMEOUT', 180),
    ],

    // ── Production AI APIs ────────────────────────────────────────────────────

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
        'image_compression' => (int) env('OPENAI_IMAGE_COMPRESSION', 50), // 0-100 (jpeg/webp only)
        'image_stitch' => filter_var(env('OPENAI_IMAGE_STITCH', true), FILTER_VALIDATE_BOOLEAN), // 2-image stitched panorama
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 2048),
        'tts_model' => env('OPENAI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('OPENAI_TTS_VOICE', 'alloy'),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'base_url' => 'https://api.elevenlabs.io',
        // Narration voice for the Time-Map "read summary aloud" feature (George by default).
        'voice_id' => env('ELEVENLABS_VOICE_ID', 'JBFqnCBsd6RMkjVDRZzb'),
    ],

    'rhubarb' => [
        'binary' => env('RHUBARB_BINARY', '/Users/bartslot/bin/rhubarb'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    // ── Image search (lesson slideshow backgrounds) ───────────────────────────

    'europeana' => [
        'key' => env('EUROPEANA_API_KEY'),
    ],

    'unsplash' => [
        'access_key' => env('UNSPLASH_ACCESS_KEY'),
    ],

    'pexels' => [
        'api_key' => env('PEXELS_API_KEY'),
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
    'lmstudio' => [
        'url' => env('LM_STUDIO_URL', 'http://localhost:1234/v1'),
        'model' => env('LM_STUDIO_MODEL', 'google/gemma-4-e4b'),
    ],

    // ── Automatic1111 (local Stable Diffusion image generation) ──────────────
    'a1111' => [
        'url' => env('A1111_URL', 'http://localhost:7860'),
        'sampler' => env('A1111_SAMPLER', 'Euler a'),
        'steps' => (int) env('A1111_STEPS', 20),
        'cfg' => (float) env('A1111_CFG', 7.0),
        'scene_width' => (int) env('A1111_SCENE_WIDTH', 1024),
        'scene_height' => (int) env('A1111_SCENE_HEIGHT', 512),
        'skybox_width' => (int) env('A1111_SKYBOX_WIDTH', 2048),
        'skybox_height' => (int) env('A1111_SKYBOX_HEIGHT', 1024),
        'timeout' => (int) env('A1111_TIMEOUT', 120),
    ],

    // ── Upscayl local CLI (replaces fal.ai upscaling in dev) ─────────────────
    'upscayl' => [
        'enabled' => filter_var(env('UPSCAYL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'bin' => env('UPSCAYL_BIN', '/Applications/Upscayl.app/Contents/Resources/bin/upscayl-bin'),
        'model_path' => env('UPSCAYL_MODEL_PATH', '/Applications/Upscayl.app/Contents/Resources/models'),
        'model' => env('UPSCAYL_MODEL', 'realesrgan-x4plus'),
    ],

    // ── Animation tooling ─────────────────────────────────────────────────────

    'fbx2gltf' => [
        'binary' => env('FBX2GLTF_BINARY', 'fbx2gltf'),
    ],

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

    // ── WorldLabs Marble (3D Gaussian-splat world from image) ─────────────────

    'worldlabs' => [
        'api_key' => env('WORLD_LABS_API_KEY'),
        'enabled' => filter_var(env('WORLD_LABS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
