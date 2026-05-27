-- =============================================================================
-- History Corpus — Supabase Setup
-- =============================================================================
-- Paste this entire file into the Supabase SQL Editor and click "Run".
-- You only need to run it ONCE when setting up a new Supabase project.
--
-- What this does:
--   1. Enables required extensions (pgvector, pg_trgm)
--   2. Creates all tables (history_articles, history_facts, scrape_log)
--   3. Adds full-text search columns + GIN indexes
--   4. Adds an HNSW vector index for fast (<5 ms) similarity search
--   5. Creates auto-update triggers to keep tsvector columns fresh
--
-- After running this, update your .env:
--   DATABASE_URL=postgresql+asyncpg://postgres:[PASSWORD]@db.[PROJECT_REF].supabase.co:5432/postgres
-- =============================================================================


-- ── 0. Extensions ─────────────────────────────────────────────────────────────

CREATE EXTENSION IF NOT EXISTS vector;      -- pgvector (pre-installed on Supabase)
CREATE EXTENSION IF NOT EXISTS pg_trgm;     -- trigram similarity (helps FTS & LIKE)
CREATE EXTENSION IF NOT EXISTS unaccent;    -- normalize accented chars in search


-- ── 1. Enums ──────────────────────────────────────────────────────────────────

DO $$ BEGIN
    CREATE TYPE source_name   AS ENUM ('whe', 'wikipedia', 'gutenberg', 'loc');
EXCEPTION WHEN duplicate_object THEN null; END $$;

DO $$ BEGIN
    CREATE TYPE confidence_level AS ENUM ('high', 'med', 'low');
EXCEPTION WHEN duplicate_object THEN null; END $$;

DO $$ BEGIN
    CREATE TYPE grade_level   AS ENUM ('K6', 'K8', 'K12');
EXCEPTION WHEN duplicate_object THEN null; END $$;

DO $$ BEGIN
    CREATE TYPE scrape_status AS ENUM ('pending', 'scraped', 'extracted', 'embedded', 'failed');
EXCEPTION WHEN duplicate_object THEN null; END $$;


-- ── 2. Tables ─────────────────────────────────────────────────────────────────

-- history_articles ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS history_articles (
    id               BIGSERIAL PRIMARY KEY,

    -- Identity
    source           source_name   NOT NULL,
    source_id        VARCHAR(512)  NOT NULL,
    source_url       VARCHAR(1024) NOT NULL,
    source_license   VARCHAR(128),

    -- Content
    title            VARCHAR(512)  NOT NULL,
    summary          TEXT,
    full_text        TEXT,
    author           VARCHAR(256),
    published_date   VARCHAR(64),

    -- Classification
    period           VARCHAR(128),
    region           VARCHAR(128),
    era_start        INTEGER,      -- year as integer; negative = BCE
    era_end          INTEGER,
    tags             TEXT[],
    categories       TEXT[],

    -- Quality
    confidence       confidence_level NOT NULL DEFAULT 'med',
    verified_by_sources SMALLINT  NOT NULL DEFAULT 1,
    word_count       INTEGER,

    -- Vector embedding (768-dim for nomic-embed-text, or 1536 for OpenAI)
    -- NOTE: If you switch models change this dimension and recreate the index.
    embedding        vector(768),

    -- JSONB: visual context from LLM extraction
    -- keys: era_label, clothing, architecture, colours, materials, typical_scene
    extra            JSONB,

    -- Full-text search vector (auto-maintained by trigger below)
    search_vector    TSVECTOR,

    -- Pipeline state
    status           scrape_status NOT NULL DEFAULT 'scraped',

    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_article_source_id UNIQUE (source, source_id)
);

-- history_facts ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS history_facts (
    id               BIGSERIAL PRIMARY KEY,
    article_id       BIGINT NOT NULL REFERENCES history_articles(id) ON DELETE CASCADE,

    -- The fact itself
    fact_text        TEXT NOT NULL,

    -- Structured metadata (AI-extracted)
    people           TEXT[],
    places           TEXT[],
    dates            TEXT[],
    events           TEXT[],
    tags             TEXT[],

    -- Scene-ready location + era fields
    -- location   : "City, Country" e.g. "Rome, Italy" | "Nile Delta, Egypt"
    -- start_year : signed integer (negative = BCE) e.g. -44, 1945
    -- end_year   : equals start_year when single point in time
    -- year_display: Scene.year-ready string e.g. "44 BCE" | "1914 – 1918"
    location         VARCHAR(256),
    start_year       INTEGER,
    end_year         INTEGER,
    year_display     VARCHAR(64),

    -- Grade suitability
    grade_level      grade_level      NOT NULL DEFAULT 'K12',

    -- Quality
    confidence       confidence_level NOT NULL DEFAULT 'med',
    verified_by_sources SMALLINT     NOT NULL DEFAULT 1,

    -- Extra structured data
    extra            JSONB,

    -- Full-text search vector (auto-maintained by trigger below)
    search_vector    TSVECTOR,

    created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- scrape_log ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS scrape_log (
    id               BIGSERIAL PRIMARY KEY,
    url              VARCHAR(1024) NOT NULL UNIQUE,
    source           source_name   NOT NULL,
    status           scrape_status NOT NULL DEFAULT 'pending',
    article_id       BIGINT,
    error_msg        TEXT,
    attempts         SMALLINT      NOT NULL DEFAULT 0,
    last_attempted   TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);


-- ── 3. Scalar indexes ─────────────────────────────────────────────────────────

-- history_articles
CREATE INDEX IF NOT EXISTS idx_articles_source        ON history_articles (source);
CREATE INDEX IF NOT EXISTS idx_articles_title         ON history_articles (title);
CREATE INDEX IF NOT EXISTS idx_articles_period        ON history_articles (period);
CREATE INDEX IF NOT EXISTS idx_articles_region        ON history_articles (region);
CREATE INDEX IF NOT EXISTS idx_articles_status        ON history_articles (status);
CREATE INDEX IF NOT EXISTS idx_articles_confidence    ON history_articles (confidence);

-- history_facts
CREATE INDEX IF NOT EXISTS idx_facts_article_id       ON history_facts (article_id);
CREATE INDEX IF NOT EXISTS idx_facts_grade_level      ON history_facts (grade_level);
CREATE INDEX IF NOT EXISTS idx_facts_confidence       ON history_facts (confidence);
CREATE INDEX IF NOT EXISTS idx_facts_location         ON history_facts (location);
CREATE INDEX IF NOT EXISTS idx_facts_start_year       ON history_facts (start_year);

-- Composite index: most common lesson-generation query pattern
CREATE INDEX IF NOT EXISTS idx_facts_grade_conf
    ON history_facts (grade_level, confidence);

-- scrape_log
CREATE INDEX IF NOT EXISTS idx_scrape_log_url         ON scrape_log (url);
CREATE INDEX IF NOT EXISTS idx_scrape_log_status      ON scrape_log (status);


-- ── 4. GIN full-text search indexes ──────────────────────────────────────────

-- Articles: search on title + summary
CREATE INDEX IF NOT EXISTS idx_articles_fts
    ON history_articles USING GIN (search_vector);

-- Facts: search on fact_text
CREATE INDEX IF NOT EXISTS idx_facts_fts
    ON history_facts USING GIN (search_vector);

-- Tags array search (GIN for @> and && operators)
CREATE INDEX IF NOT EXISTS idx_articles_tags
    ON history_articles USING GIN (tags);

CREATE INDEX IF NOT EXISTS idx_facts_tags
    ON history_facts USING GIN (tags);


-- ── 5. HNSW vector index ──────────────────────────────────────────────────────
-- HNSW is faster than IVFFlat for < 1M rows and needs no training pass.
-- ef_construction=64 and m=16 give a good recall/speed trade-off.
-- Supabase default storage: 500 MB free tier fits ~20K articles at 768-dim.
--
-- IMPORTANT: The embedding column dimension (768) MUST match EMBED_DIM in .env.
-- If you switch to OpenAI text-embedding-3-small (1536-dim), drop and recreate:
--   ALTER TABLE history_articles ALTER COLUMN embedding TYPE vector(1536);
--   DROP INDEX IF EXISTS idx_articles_hnsw;
--   CREATE INDEX idx_articles_hnsw ON history_articles USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64);

CREATE INDEX IF NOT EXISTS idx_articles_hnsw
    ON history_articles
    USING hnsw (embedding vector_cosine_ops)
    WITH (m = 16, ef_construction = 64);


-- ── 6. tsvector trigger functions ────────────────────────────────────────────

-- Articles: build search_vector from title + summary
CREATE OR REPLACE FUNCTION articles_tsvector_update()
RETURNS TRIGGER AS $$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('english', coalesce(NEW.title, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(NEW.summary, '')), 'B');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trig_articles_tsvector ON history_articles;
CREATE TRIGGER trig_articles_tsvector
    BEFORE INSERT OR UPDATE OF title, summary
    ON history_articles
    FOR EACH ROW EXECUTE FUNCTION articles_tsvector_update();


-- Facts: build search_vector from fact_text
CREATE OR REPLACE FUNCTION facts_tsvector_update()
RETURNS TRIGGER AS $$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('english', coalesce(NEW.fact_text, '')), 'A');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trig_facts_tsvector ON history_facts;
CREATE TRIGGER trig_facts_tsvector
    BEFORE INSERT OR UPDATE OF fact_text
    ON history_facts
    FOR EACH ROW EXECUTE FUNCTION facts_tsvector_update();


-- Articles: auto-update updated_at
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trig_articles_updated_at ON history_articles;
CREATE TRIGGER trig_articles_updated_at
    BEFORE UPDATE ON history_articles
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ── 7. Hybrid search function (Reciprocal Rank Fusion) ───────────────────────
-- Combines BM25 full-text rank + cosine vector similarity via RRF.
-- Called from PHP HistoryCorpusService::factsForLessonHybrid().
--
-- Parameters:
--   query_text      : plain English query e.g. "Roman Empire trade"
--   query_embedding : vector from nomic-embed-text (768-dim)
--   grade_levels    : array of allowed grade levels e.g. ARRAY['K6','K8']
--   match_count     : number of results to return
--   rrf_k           : RRF constant (default 60, standard)

CREATE OR REPLACE FUNCTION hybrid_fact_search(
    query_text      TEXT,
    query_embedding vector(768),
    grade_levels    TEXT[]    DEFAULT ARRAY['K6','K8','K12'],
    match_count     INT       DEFAULT 15,
    rrf_k           INT       DEFAULT 60
)
RETURNS TABLE (
    fact_id             BIGINT,
    fact_text           TEXT,
    people              TEXT[],
    places              TEXT[],
    dates               TEXT[],
    events              TEXT[],
    tags                TEXT[],
    location            VARCHAR(256),
    start_year          INTEGER,
    end_year            INTEGER,
    year_display        VARCHAR(64),
    grade_level         grade_level,
    confidence          confidence_level,
    article_title       VARCHAR(512),
    source_url          VARCHAR(1024),
    source_license      VARCHAR(128),
    rrf_score           FLOAT
)
LANGUAGE sql
AS $$
    WITH

    -- ── BM25 full-text ranking ─────────────────────────────────────────────
    fts_ranked AS (
        SELECT
            f.id,
            row_number() OVER (
                ORDER BY
                    ts_rank_cd(a.search_vector, plainto_tsquery('english', query_text)) +
                    ts_rank_cd(f.search_vector, plainto_tsquery('english', query_text))
                DESC
            ) AS rank
        FROM history_facts f
        JOIN history_articles a ON a.id = f.article_id
        WHERE
            f.grade_level = ANY(grade_levels::grade_level[])
            AND (
                a.search_vector @@ plainto_tsquery('english', query_text)
                OR f.search_vector @@ plainto_tsquery('english', query_text)
            )
        LIMIT match_count * 4   -- overfetch for fusion
    ),

    -- ── Vector cosine similarity ranking ──────────────────────────────────
    vec_ranked AS (
        SELECT
            f.id,
            row_number() OVER (
                ORDER BY a.embedding <=> query_embedding ASC
            ) AS rank
        FROM history_facts f
        JOIN history_articles a ON a.id = f.article_id
        WHERE
            f.grade_level = ANY(grade_levels::grade_level[])
            AND a.embedding IS NOT NULL
        LIMIT match_count * 4
    ),

    -- ── Reciprocal Rank Fusion ─────────────────────────────────────────────
    rrf AS (
        SELECT
            COALESCE(fts.id, vec.id) AS id,
            COALESCE(1.0 / (rrf_k + fts.rank), 0) +
            COALESCE(1.0 / (rrf_k + vec.rank), 0) AS rrf_score
        FROM fts_ranked fts
        FULL OUTER JOIN vec_ranked vec ON fts.id = vec.id
    )

    SELECT
        f.id,
        f.fact_text,
        f.people,
        f.places,
        f.dates,
        f.events,
        f.tags,
        f.location,
        f.start_year,
        f.end_year,
        f.year_display,
        f.grade_level,
        f.confidence,
        a.title,
        a.source_url,
        a.source_license,
        rrf.rrf_score
    FROM rrf
    JOIN history_facts f    ON f.id = rrf.id
    JOIN history_articles a ON a.id = f.article_id
    ORDER BY rrf.rrf_score DESC
    LIMIT match_count;
$$;


-- ── 8. Backfill tsvector for existing rows ───────────────────────────────────
-- Run these after bulk-inserting data if triggers didn't fire (e.g. via COPY):

UPDATE history_articles
SET search_vector =
    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(summary, '')), 'B')
WHERE search_vector IS NULL;

UPDATE history_facts
SET search_vector = setweight(to_tsvector('english', coalesce(fact_text, '')), 'A')
WHERE search_vector IS NULL;


-- ── Done ──────────────────────────────────────────────────────────────────────
-- Your Supabase corpus database is ready.
-- Next steps:
--   1. Copy the connection string from Supabase → Settings → Database
--   2. Set DATABASE_URL in history-scraper/.env
--   3. Run: python cli.py run-all
