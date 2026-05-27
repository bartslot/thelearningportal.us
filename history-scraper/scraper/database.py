"""
Database connection, session management, and initial schema setup.

For Supabase / any PostgreSQL host:
  Run supabase-setup.sql ONCE in the Supabase SQL editor to create tables,
  indexes, the HNSW vector index, and the hybrid_fact_search() function.

  init_db() below is a lightweight Python-side init that:
   - Ensures the pgvector + pg_trgm extensions exist
   - Creates tables via SQLAlchemy (safe for first-time local setup)
   - Creates HNSW + FTS indexes if they don't yet exist
   - Backfills tsvector columns on existing rows

For Supabase (recommended): use supabase-setup.sql instead.
For local Docker:            init_db() handles everything automatically.
"""
from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import AsyncGenerator

from sqlalchemy import text
from sqlalchemy.ext.asyncio import (
    AsyncSession,
    async_sessionmaker,
    create_async_engine,
)

from .config import cfg
from .models import Base

logger = logging.getLogger(__name__)

engine = create_async_engine(
    cfg.DATABASE_URL,
    echo=False,
    # Supabase / PgBouncer: keep pool modest to avoid exceeding connection limits
    pool_size=5,
    max_overflow=10,
    pool_pre_ping=True,
    # Required for Supabase (SSL by default; asyncpg handles this)
    connect_args={"ssl": "prefer"} if "supabase" in cfg.DATABASE_URL else {},
)

AsyncSessionLocal = async_sessionmaker(
    engine,
    class_=AsyncSession,
    expire_on_commit=False,
)


@asynccontextmanager
async def get_session() -> AsyncGenerator[AsyncSession, None]:
    async with AsyncSessionLocal() as session:
        try:
            yield session
            await session.commit()
        except Exception:
            await session.rollback()
            raise


async def init_db() -> None:
    """
    Idempotent DB setup. Safe to call on every startup.

    On Supabase:  run supabase-setup.sql once, then this is a no-op.
    On local Docker: this creates everything from scratch.
    """
    embed_dim = cfg.EMBED_DIM  # 768 (nomic) or 1536 (OpenAI)

    async with engine.begin() as conn:

        # ── Extensions ────────────────────────────────────────────────────────
        await conn.execute(text("CREATE EXTENSION IF NOT EXISTS vector"))
        await conn.execute(text("CREATE EXTENSION IF NOT EXISTS pg_trgm"))
        logger.info("Extensions ready (vector, pg_trgm)")

        # ── Tables (SQLAlchemy DDL) ────────────────────────────────────────────
        await conn.run_sync(Base.metadata.create_all)
        logger.info("Tables created / verified")

        # ── Embedding column dimension guard ──────────────────────────────────
        # If the column already exists with a different dimension, warn loudly.
        # Changing dimension requires: DROP INDEX + ALTER COLUMN + recreate index.
        await conn.execute(text(f"""
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'history_articles'
                      AND column_name = 'embedding'
                ) THEN
                    ALTER TABLE history_articles
                        ADD COLUMN embedding vector({embed_dim});
                END IF;
            END $$;
        """))

        # ── add search_vector columns if they don't exist ─────────────────────
        await conn.execute(text("""
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'history_articles'
                      AND column_name = 'search_vector'
                ) THEN
                    ALTER TABLE history_articles ADD COLUMN search_vector tsvector;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'history_facts'
                      AND column_name = 'search_vector'
                ) THEN
                    ALTER TABLE history_facts ADD COLUMN search_vector tsvector;
                END IF;
            END $$;
        """))

        # ── Scalar indexes ────────────────────────────────────────────────────
        _idx_stmts = [
            "CREATE INDEX IF NOT EXISTS idx_articles_source     ON history_articles (source)",
            "CREATE INDEX IF NOT EXISTS idx_articles_period     ON history_articles (period)",
            "CREATE INDEX IF NOT EXISTS idx_articles_region     ON history_articles (region)",
            "CREATE INDEX IF NOT EXISTS idx_articles_status     ON history_articles (status)",
            "CREATE INDEX IF NOT EXISTS idx_articles_confidence ON history_articles (confidence)",
            "CREATE INDEX IF NOT EXISTS idx_facts_article_id    ON history_facts (article_id)",
            "CREATE INDEX IF NOT EXISTS idx_facts_grade_level   ON history_facts (grade_level)",
            "CREATE INDEX IF NOT EXISTS idx_facts_confidence    ON history_facts (confidence)",
            "CREATE INDEX IF NOT EXISTS idx_facts_location      ON history_facts (location)",
            "CREATE INDEX IF NOT EXISTS idx_facts_start_year    ON history_facts (start_year)",
            "CREATE INDEX IF NOT EXISTS idx_facts_grade_conf    ON history_facts (grade_level, confidence)",
            "CREATE INDEX IF NOT EXISTS idx_scrape_log_url      ON scrape_log (url)",
            "CREATE INDEX IF NOT EXISTS idx_scrape_log_status   ON scrape_log (status)",
        ]
        for stmt in _idx_stmts:
            await conn.execute(text(stmt))

        # ── GIN full-text search indexes ──────────────────────────────────────
        await conn.execute(text("""
            CREATE INDEX IF NOT EXISTS idx_articles_fts
                ON history_articles USING GIN (search_vector)
        """))
        await conn.execute(text("""
            CREATE INDEX IF NOT EXISTS idx_facts_fts
                ON history_facts USING GIN (search_vector)
        """))
        await conn.execute(text("""
            CREATE INDEX IF NOT EXISTS idx_articles_tags
                ON history_articles USING GIN (tags)
        """))
        await conn.execute(text("""
            CREATE INDEX IF NOT EXISTS idx_facts_tags
                ON history_facts USING GIN (tags)
        """))

        # ── HNSW vector index ─────────────────────────────────────────────────
        # Only create when the embedding column has the expected dimension.
        # HNSW with m=16, ef_construction=64 → ~5 ms per query at 20K articles.
        await conn.execute(text(f"""
            CREATE INDEX IF NOT EXISTS idx_articles_hnsw
                ON history_articles
                USING hnsw (embedding vector_cosine_ops)
                WITH (m = 16, ef_construction = 64)
        """))
        logger.info("HNSW vector index ready (dim=%d)", embed_dim)

        # ── tsvector triggers ─────────────────────────────────────────────────
        await conn.execute(text("""
            CREATE OR REPLACE FUNCTION articles_tsvector_update()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('english', coalesce(NEW.title, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(NEW.summary, '')), 'B');
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        """))
        await conn.execute(text("""
            DROP TRIGGER IF EXISTS trig_articles_tsvector ON history_articles;
            CREATE TRIGGER trig_articles_tsvector
                BEFORE INSERT OR UPDATE OF title, summary
                ON history_articles
                FOR EACH ROW EXECUTE FUNCTION articles_tsvector_update();
        """))

        await conn.execute(text("""
            CREATE OR REPLACE FUNCTION facts_tsvector_update()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('english', coalesce(NEW.fact_text, '')), 'A');
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        """))
        await conn.execute(text("""
            DROP TRIGGER IF EXISTS trig_facts_tsvector ON history_facts;
            CREATE TRIGGER trig_facts_tsvector
                BEFORE INSERT OR UPDATE OF fact_text
                ON history_facts
                FOR EACH ROW EXECUTE FUNCTION facts_tsvector_update();
        """))

        # ── Backfill tsvector for existing rows ───────────────────────────────
        await conn.execute(text("""
            UPDATE history_articles
            SET search_vector =
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(summary, '')), 'B')
            WHERE search_vector IS NULL
        """))
        await conn.execute(text("""
            UPDATE history_facts
            SET search_vector =
                setweight(to_tsvector('english', coalesce(fact_text, '')), 'A')
            WHERE search_vector IS NULL
        """))

        logger.info("tsvector columns updated")

    logger.info("Database initialisation complete")
