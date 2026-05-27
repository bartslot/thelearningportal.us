# History Corpus Scraper

Builds a PostgreSQL database of **verified historical facts** from trusted open-license sources
(World History Encyclopedia + Wikipedia). The corpus powers the AI lesson script generator in
thelearningportal.us — guaranteeing zero hallucinations by grounding every LLM prompt in facts
that actually exist in the record.

---

## What it does

```
Sources (WHE + Wikipedia)
        ↓  scrape
history_articles   — full article text, metadata, period, region
        ↓  extract (Gemma via Ollama — free & offline)
history_facts      — atomic verifiable facts with location, year, grade level, visual context
        ↓  cross-verify
confidence = "high"  — facts confirmed in ≥2 independent sources
        ↓  embed (nomic-embed-text via Ollama — free & offline)
history_articles.embedding  — 768-dim pgvector column
        ↓  query (from Laravel)
HistoryCorpusService::factsForLessonHybrid()  — RRF: BM25 + vector cosine
        ↓
LLM system prompt  — grounded context → zero hallucinations
```

---

## Quick start

### 1. Set up Supabase (recommended)

1. Create a free account at <https://supabase.com>
2. New project → **Settings → Database → Connection string** → copy the URI
3. Open the **SQL Editor** in Supabase dashboard
4. Paste the contents of [`supabase-setup.sql`](supabase-setup.sql) and click **Run**
   — this creates all tables, indexes, the HNSW vector index, and the hybrid search function

### 2. Configure the scraper

```bash
cd history-scraper
cp .env.example .env
# Edit .env:
#   DATABASE_URL=postgresql+asyncpg://postgres:[PASSWORD]@db.[REF].supabase.co:5432/postgres
#   OLLAMA_URL=http://localhost:11434
#   LLM_MODEL=gemma3
```

### 3. Pull required Ollama models

```bash
ollama pull gemma3              # LLM extractor  (~5 GB)
ollama pull nomic-embed-text    # Embedding model (~270 MB, fast)
```

### 4. Install Python dependencies

```bash
pip install -r requirements.txt
```

### 5. Run the full pipeline

```bash
# Initialise DB (idempotent — safe to run again if already set up via Supabase SQL)
python cli.py init-db

# Run everything: scrape → extract → cross-verify → embed
python cli.py run-all

# Or step by step:
python cli.py scrape whe         # ~34K WHE articles
python cli.py scrape wikipedia   # ~10K Wikipedia articles
python cli.py extract            # Gemma: extract facts + visual context
python cli.py verify             # Cross-verify WHE ↔ Wikipedia
python cli.py embed              # nomic-embed-text: generate vectors

# Check progress
python cli.py stats
```

---

## Alternative: Local Docker

If you don't want Supabase, run a local `pgvector` PostgreSQL instead:

```bash
docker compose up -d db    # starts postgres + pgvector on port 5432
# Set in .env:
# DATABASE_URL=postgresql+asyncpg://history:secret@localhost:5432/history_corpus
python cli.py init-db      # creates schema automatically
```

---

## Laravel integration

Copy these files into your Laravel app:

| File | Destination |
|---|---|
| `laravel-integration/HistoryCorpusService.php` | `app/Services/HistoryCorpusService.php` |
| `laravel-integration/CorpusEmbeddingService.php` | `app/Services/CorpusEmbeddingService.php` |
| `laravel-integration/config/corpus_database.php` | Follow instructions inside the file |

Add to your `.env`:

```env
CORPUS_DB_HOST=db.<project-ref>.supabase.co
CORPUS_DB_PORT=5432
CORPUS_DB_DATABASE=postgres
CORPUS_DB_USERNAME=postgres
CORPUS_DB_PASSWORD=<supabase-db-password>

CORPUS_EMBED_BACKEND=ollama
CORPUS_EMBED_MODEL=nomic-embed-text
OLLAMA_URL=http://localhost:11434
```

Usage in lesson generator:

```php
use App\Services\HistoryCorpusService;
use App\Services\CorpusEmbeddingService;

// Hybrid search (best quality)
$embedding = CorpusEmbeddingService::embed('Roman Empire trade routes');
$facts     = HistoryCorpusService::factsForLessonHybrid(
    topic:          'Roman Empire',
    queryEmbedding: $embedding,
    gradeLevel:     'K8',
    count:          15,
);

// Build grounding context for Claude
$context = HistoryCorpusService::toGroundingPrompt($facts, 'Roman Empire');

// $context is now ready to inject into your LLM system prompt
```

---

## Schema overview

### `history_articles`
| Column | Type | Notes |
|---|---|---|
| `title` | varchar | Article title |
| `summary` | text | First paragraph |
| `full_text` | text | Full article body |
| `period` | varchar | Ancient / Medieval / Early Modern / Modern |
| `region` | varchar | Mediterranean / Americas / East Asia … |
| `era_start` / `era_end` | integer | Signed: negative = BCE |
| `embedding` | vector(768) | nomic-embed-text; HNSW indexed |
| `search_vector` | tsvector | Auto-updated by trigger |
| `extra` | jsonb | Visual context: clothing, architecture, colours … |

### `history_facts`
| Column | Type | Notes |
|---|---|---|
| `fact_text` | text | One verifiable sentence |
| `location` | varchar(256) | "City, Country" — maps to `Scene.location` |
| `year_display` | varchar(64) | "44 BCE" / "1914 – 1918" — maps to `Scene.year` |
| `start_year` / `end_year` | integer | Signed; equal for single-point events |
| `grade_level` | enum | K6 / K8 / K12 |
| `confidence` | enum | high / med / low |
| `people`, `places`, `dates`, `events`, `tags` | text[] | Structured extraction |

---

## Embedding dimensions

| Model | Backend | Dim | Cost |
|---|---|---|---|
| `nomic-embed-text` | Ollama (local) | 768 | Free |
| `text-embedding-3-small` | OpenAI | 1536 | ~$0.30 / corpus |

The default is `nomic-embed-text` (768-dim). If you switch to OpenAI:
1. Update `supabase-setup.sql` line: `embedding vector(1536)`
2. Set `EMBED_DIM=1536` in `.env`
3. Drop + recreate the HNSW index (see comment in `supabase-setup.sql`)

---

## License

Source content is CC BY-NC-SA (World History Encyclopedia) and CC BY-SA (Wikipedia).
Attribution is stored in `history_articles.source_license` and returned in every query result.
The `toGroundingPrompt()` output includes source URLs and license strings as required.
