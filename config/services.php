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

    'kokoro' => [
        'url' => env('KOKORO_TTS_URL', 'http://localhost:8880'),
    ],

    'sadtalker' => [
        'url'    => env('SADTALKER_URL'),
        'dir'    => env('SADTALKER_DIR'),
        'python' => env('SADTALKER_PYTHON'),
    ],

    'comfyui' => [
        'url' => env('COMFYUI_URL', 'http://localhost:8188'),
    ],

    // ── Production AI APIs ────────────────────────────────────────────────────

    'openai' => [
        'api_key'   => env('OPENAI_API_KEY'),
        'tts_model' => env('OPENAI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('OPENAI_TTS_VOICE', 'alloy'),
    ],

    'rhubarb' => [
        'binary' => env('RHUBARB_BINARY', '/Users/bartslot/bin/rhubarb'),
    ],

    'fal' => [
        'api_key' => env('FAL_AI_KEY'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    // ── Image search (lesson slideshow backgrounds) ───────────────────────────

    'europeana' => [
        'key' => env('EUROPEANA_API_KEY'),
    ],

    // ── Vercel serverless functions (avatar studio / audio manifest) ──────────

    'vercel_functions' => [
        'url' => env('VERCEL_FUNCTIONS_URL', 'http://localhost:3000'),
    ],

    // ── Azure Speech Service (3D avatar lip sync) ─────────────────────────────

    'azure_speech' => [
        'key'    => env('AZURE_SPEECH_KEY'),
        'region' => env('AZURE_SPEECH_REGION', 'eastus'),
    ],

];
