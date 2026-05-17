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
        'url'   => env('OLLAMA_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.1:8b'),
    ],

    'comfyui' => [
        'url' => env('COMFYUI_URL', 'http://localhost:8188'),
    ],

    // ── Production AI APIs ────────────────────────────────────────────────────

    'openai' => [
        'api_key'      => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'model'        => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'image_model'       => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        'image_size'        => env('OPENAI_IMAGE_SIZE', '1536x1024'),
        'image_format'      => env('OPENAI_IMAGE_FORMAT', 'webp'),       // png|jpeg|webp
        'image_compression' => (int) env('OPENAI_IMAGE_COMPRESSION', 50), // 0-100 (jpeg/webp only)
        'image_stitch'      => filter_var(env('OPENAI_IMAGE_STITCH', true), FILTER_VALIDATE_BOOLEAN), // 2-image stitched panorama
        'base_url'     => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout'      => (int) env('OPENAI_TIMEOUT', 60),
        'tts_model'    => env('OPENAI_TTS_MODEL', 'tts-1'),
        'tts_voice'    => env('OPENAI_TTS_VOICE', 'alloy'),
    ],

    'elevenlabs' => [
        'api_key'  => env('ELEVENLABS_API_KEY'),
        'base_url' => 'https://api.elevenlabs.io',
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
        'api_key'         => env('FAL_AI_KEY'),
        'upscale_enabled' => filter_var(env('FAL_UPSCALE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'upscale_model'   => env('FAL_UPSCALE_MODEL', 'fal-ai/clarity-upscaler'),
        'upscale_factor'  => (int) env('FAL_UPSCALE_FACTOR', 2),
        'timeout'         => (int) env('FAL_UPSCALE_TIMEOUT', 120),
    ],

    // ── Vercel serverless functions (avatar studio / audio manifest) ──────────

    'vercel_functions' => [
        'url' => env('VERCEL_FUNCTIONS_URL', 'http://localhost:3000'),
    ],

    // ── Animation tooling ─────────────────────────────────────────────────────

    'fbx2gltf' => [
        'binary' => env('FBX2GLTF_BINARY', 'fbx2gltf'),
    ],

    // ── Azure Speech Service (3D avatar lip sync) ─────────────────────────────

    'azure_speech' => [
        'key'    => env('AZURE_SPEECH_KEY'),
        'region' => env('AZURE_SPEECH_REGION', 'eastus'),
    ],

    // ── Pocket TTS (local voice cloning) ──────────────────────────────────────

    'pocket_tts' => [
        'url'      => env('POCKET_TTS_URL', 'http://localhost:8001'),
        'hf_token' => env('HF_TOKEN'),
    ],

];
