<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Language models: who writes what
|--------------------------------------------------------------------------
|
| Every provider here speaks the OpenAI chat/completions wire format — OpenAI
| itself, Gemini/Gemma, Groq, DeepSeek, OpenRouter and a local Ollama all do —
| so a provider is nothing more than a base URL, a key and a model name. That
| is what makes trying a new (or free) model a .env edit rather than a code
| change.
|
| ROLES are the point of this file. A lesson's narration script is the product
| and deserves the best model available; naming a shot list or writing three
| wrong quiz answers does not. Splitting calls by role lets the script run on a
| strong model while the mechanical work runs on a cheap or free one, and lets
| you test a candidate model on the mechanical calls before trusting it with
| the writing.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | `json_format` is the value sent as response_format.type when a call asks
    | for JSON. Leave it empty for models that reject the parameter (Ollama,
    | LM Studio, some Gemma builds) — the service already strips markdown
    | fences and digs the first JSON object out of prose, so those models still
    | work, just without the server-side guarantee.
    |
    */

    'providers' => [

        'openai' => [
            'label' => 'OpenAI',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'json_format' => env('OPENAI_JSON_FORMAT', 'json_object'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        ],

        // Gemini's OpenAI-compatible endpoint. Also serves the open Gemma models
        // (gemma-3-27b-it and friends) on the free tier — the cheapest way to try
        // a competent model without a credit balance anywhere.
        'gemini' => [
            'label' => 'Google AI Studio (Gemini / Gemma)',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemma-3-27b-it'),
            'json_format' => env('GEMINI_JSON_FORMAT', 'json_object'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 90),
        ],

        // Free tier, very fast, open-weight models (Llama, Qwen, Gemma).
        'groq' => [
            'label' => 'Groq',
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'api_key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'json_format' => env('GROQ_JSON_FORMAT', 'json_object'),
            'timeout' => (int) env('GROQ_TIMEOUT', 60),
        ],

        'deepseek' => [
            'label' => 'DeepSeek',
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'json_format' => env('DEEPSEEK_JSON_FORMAT', 'json_object'),
            'timeout' => (int) env('DEEPSEEK_TIMEOUT', 90),
        ],

        // One key, many models — handy for a bake-off across vendors.
        'openrouter' => [
            'label' => 'OpenRouter',
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL', 'google/gemma-3-27b-it:free'),
            'json_format' => env('OPENROUTER_JSON_FORMAT', 'json_object'),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 120),
        ],

        // Local, free, private — no key. Slow on a laptop, but nothing leaves the machine.
        'ollama' => [
            'label' => 'Ollama (local)',
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
            'api_key' => env('OLLAMA_API_KEY', 'ollama'),   // ignored, but the client sends a bearer
            'model' => env('OLLAMA_MODEL', 'gemma3:12b'),
            'json_format' => env('OLLAMA_JSON_FORMAT', ''), // rejects response_format
            'timeout' => (int) env('OLLAMA_TIMEOUT', 600),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | Which provider handles which kind of work. Each may name a provider on its
    | own ("gemini") or a provider and model ("gemini:gemma-3-27b-it"), so one
    | key can serve several roles at different model sizes.
    |
    |   script     the lesson's narration — the thing a class actually hears
    |   mechanical quiz distractors, shot lists, image prompts, alt text
    |   vision     looking at an image and describing it
    |
    */

    'roles' => [
        'script' => env('LLM_ROLE_SCRIPT', 'openai'),
        'mechanical' => env('LLM_ROLE_MECHANICAL', 'openai'),
        'vision' => env('LLM_ROLE_VISION', 'openai'),
    ],

    /** Role used when a call site asks for one that isn't configured. */
    'default_role' => env('LLM_DEFAULT_ROLE', 'mechanical'),

];
