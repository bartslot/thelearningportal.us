<?php

/**
 * History Corpus — Laravel database connection config
 * ====================================================
 *
 * Add the 'corpus' connection block below into your app's config/database.php,
 * inside the 'connections' array:
 *
 *   'connections' => [
 *       'mysql'  => [...],   // your existing app DB
 *       'corpus' => [...],   // ← paste this block
 *   ],
 *
 * Then add these variables to your .env:
 *
 *   CORPUS_DB_HOST=db.<project-ref>.supabase.co
 *   CORPUS_DB_PORT=5432
 *   CORPUS_DB_DATABASE=postgres
 *   CORPUS_DB_USERNAME=postgres
 *   CORPUS_DB_PASSWORD=<your-supabase-db-password>
 *
 * For local Docker:
 *   CORPUS_DB_HOST=127.0.0.1
 *   CORPUS_DB_PORT=5432
 *   CORPUS_DB_DATABASE=history_corpus
 *   CORPUS_DB_USERNAME=history
 *   CORPUS_DB_PASSWORD=secret
 *
 * How to find your Supabase connection details:
 *   Supabase Dashboard → Project → Settings → Database → Connection string
 *   Copy the "Host", "Port", "Database", "User" values.
 *   The password is the one you set when creating the project.
 */

// ── corpus connection block (paste into config/database.php) ─────────────────

return [
    'corpus' => [
        'driver'         => 'pgsql',
        'host'           => env('CORPUS_DB_HOST',     '127.0.0.1'),
        'port'           => env('CORPUS_DB_PORT',     '5432'),
        'database'       => env('CORPUS_DB_DATABASE', 'history_corpus'),
        'username'       => env('CORPUS_DB_USERNAME', 'history'),
        'password'       => env('CORPUS_DB_PASSWORD', ''),
        'charset'        => 'utf8',
        'prefix'         => '',
        'prefix_indexes' => true,
        'search_path'    => 'public',
        'sslmode'        => env('CORPUS_DB_SSLMODE', 'prefer'),
        // pgvector requires no special driver config — it's transparent to PHP
    ],
];

// ── config/corpus.php (create this file in your app's config/) ───────────────
// This file holds corpus-specific settings (embedding backend, model, etc.)
//
// php artisan config:cache  will pick it up automatically.

/*
return [

    // Embedding backend: 'ollama' (free, local) or 'openai' (paid, hosted)
    'embed_backend' => env('CORPUS_EMBED_BACKEND', 'ollama'),

    // Model name passed to the backend
    // Ollama default: nomic-embed-text (768-dim, free)
    // OpenAI default: text-embedding-3-small (1536-dim, ~$0.02 per 1M tokens)
    'embed_model'   => env('CORPUS_EMBED_MODEL', 'nomic-embed-text'),

    // Ollama URL (used when embed_backend = 'ollama')
    'ollama_url'    => env('OLLAMA_URL', 'http://localhost:11434'),

];
*/
