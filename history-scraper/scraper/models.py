"""
SQLAlchemy ORM models for the history corpus.

Tables
------
history_articles  — one row per source article
history_facts     — atomic facts extracted from articles (1 article → N facts)
scrape_log        — tracks which URLs have been processed (for resumability)
"""
from __future__ import annotations

import enum
from datetime import datetime
from typing import List, Optional

from sqlalchemy import (
    BigInteger,
    DateTime,
    Enum,
    ForeignKey,
    Integer,
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB, TSVECTOR
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


# ── pgvector ──────────────────────────────────────────────────────────────────
try:
    from pgvector.sqlalchemy import Vector  # type: ignore
    _VECTOR_AVAILABLE = True
except ImportError:
    _VECTOR_AVAILABLE = False
    Vector = None  # graceful degradation if pgvector not installed yet


class Base(DeclarativeBase):
    pass


# ── Enums ─────────────────────────────────────────────────────────────────────

class SourceName(str, enum.Enum):
    WHE = "whe"                   # World History Encyclopedia
    WIKIPEDIA = "wikipedia"
    GUTENBERG = "gutenberg"
    LOC = "loc"                   # Library of Congress


class Confidence(str, enum.Enum):
    HIGH = "high"    # Verified by ≥2 independent sources
    MED  = "med"     # Single trusted source
    LOW  = "low"     # Unverified / AI-extracted only


class GradeLevel(str, enum.Enum):
    K6  = "K6"    # Kindergarten–Grade 6  (age 5–11)
    K8  = "K8"    # Grade 7–8             (age 12–13)
    K12 = "K12"   # Grade 9–12            (age 14–18)


class ScrapeStatus(str, enum.Enum):
    PENDING    = "pending"
    SCRAPED    = "scraped"
    EXTRACTED  = "extracted"   # facts extracted by LLM
    EMBEDDED   = "embedded"    # embedding vector generated
    FAILED     = "failed"


# ── history_articles ──────────────────────────────────────────────────────────

class HistoryArticle(Base):
    __tablename__ = "history_articles"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)

    # Identity
    source: Mapped[SourceName] = mapped_column(Enum(SourceName), nullable=False, index=True)
    source_id: Mapped[str] = mapped_column(String(512), nullable=False)
    source_url: Mapped[str] = mapped_column(String(1024), nullable=False)
    source_license: Mapped[str] = mapped_column(String(128), nullable=True)

    # Content
    title: Mapped[str] = mapped_column(String(512), nullable=False, index=True)
    summary: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    full_text: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    author: Mapped[Optional[str]] = mapped_column(String(256), nullable=True)
    published_date: Mapped[Optional[str]] = mapped_column(String(64), nullable=True)

    # Classification
    period: Mapped[Optional[str]] = mapped_column(String(128), nullable=True, index=True)
    region: Mapped[Optional[str]] = mapped_column(String(128), nullable=True, index=True)
    era_start: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    era_end: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    tags: Mapped[Optional[list]] = mapped_column(ARRAY(String), nullable=True)
    categories: Mapped[Optional[list]] = mapped_column(ARRAY(String), nullable=True)

    # Quality
    confidence: Mapped[Confidence] = mapped_column(
        Enum(Confidence), default=Confidence.MED, nullable=False, index=True
    )
    verified_by_sources: Mapped[int] = mapped_column(SmallInteger, default=1)
    word_count: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)

    # Embedding (pgvector)
    # Dimension matches EMBED_DIM in .env: 768 (nomic-embed-text) or 1536 (OpenAI)
    embedding: Mapped[Optional[object]] = mapped_column(
        Vector(768) if _VECTOR_AVAILABLE else Text,
        nullable=True,
    )

    # Full-text search vector (maintained by DB trigger — do not set manually)
    search_vector: Mapped[Optional[object]] = mapped_column(
        TSVECTOR,
        nullable=True,
    )

    # Visual context extracted by LLM alongside facts (JSONB)
    # Keys: era_label, clothing, architecture, colours, materials, typical_scene
    extra: Mapped[Optional[dict]] = mapped_column(JSONB, nullable=True)

    # Pipeline state
    status: Mapped[ScrapeStatus] = mapped_column(
        Enum(ScrapeStatus), default=ScrapeStatus.SCRAPED, nullable=False, index=True
    )

    # Timestamps
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime, server_default=func.now(), onupdate=func.now()
    )

    # Relationships
    facts: Mapped[List["HistoryFact"]] = relationship(
        "HistoryFact", back_populates="article", cascade="all, delete-orphan"
    )

    __table_args__ = (
        UniqueConstraint("source", "source_id", name="uq_article_source_id"),
    )

    def __repr__(self) -> str:
        return f"<HistoryArticle id={self.id} source={self.source} title={self.title!r}>"


# ── history_facts ─────────────────────────────────────────────────────────────

class HistoryFact(Base):
    """
    An atomic, verifiable fact extracted from an article.

    A 'fact' is a single sentence that:
    - Names a specific person, place, event, or concept
    - Contains at least one verifiable claim (date, number, causal relationship)
    - Can stand alone without losing meaning
    """
    __tablename__ = "history_facts"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    article_id: Mapped[int] = mapped_column(
        BigInteger, ForeignKey("history_articles.id", ondelete="CASCADE"), nullable=False, index=True
    )

    # The fact itself
    fact_text: Mapped[str] = mapped_column(Text, nullable=False)

    # Structured metadata (AI-extracted)
    people: Mapped[Optional[list]] = mapped_column(ARRAY(String), nullable=True)
    places: Mapped[Optional[list]] = mapped_column(ARRAY(String), nullable=True)
    dates: Mapped[Optional[list]] = mapped_column(ARRAY(String), nullable=True)
    events: Mapped[Optional[list]] = mapped_column(ARRAY(String), nullable=True)
    tags: Mapped[Optional[list]] = mapped_column(ARRAY(String), nullable=True)

    # ── Location + era — maps directly to Scene.location / Scene.year ─────────
    #
    # location   : "City, Country" e.g. "Rome, Italy" | "Nile Delta, Egypt"
    # start_year : signed integer (negative = BCE) e.g. -44, 1945
    # end_year   : equals start_year for single-point-in-time events
    # year_display: ready-to-use Scene.year string e.g. "44 BCE" | "1914 – 1918"
    location:     Mapped[Optional[str]] = mapped_column(String(256), nullable=True, index=True)
    start_year:   Mapped[Optional[int]] = mapped_column(Integer, nullable=True, index=True)
    end_year:     Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    year_display: Mapped[Optional[str]] = mapped_column(String(64),  nullable=True)

    # Grade-level suitability (AI-assessed)
    grade_level: Mapped[GradeLevel] = mapped_column(
        Enum(GradeLevel), default=GradeLevel.K12, nullable=False, index=True
    )

    # Quality
    confidence: Mapped[Confidence] = mapped_column(
        Enum(Confidence), default=Confidence.MED, nullable=False, index=True
    )
    verified_by_sources: Mapped[int] = mapped_column(SmallInteger, default=1)

    # Extra structured data from AI
    extra: Mapped[Optional[dict]] = mapped_column(JSONB, nullable=True)

    # Full-text search vector (maintained by DB trigger — do not set manually)
    search_vector: Mapped[Optional[object]] = mapped_column(
        TSVECTOR,
        nullable=True,
    )

    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())

    # Relationships
    article: Mapped["HistoryArticle"] = relationship("HistoryArticle", back_populates="facts")

    def __repr__(self) -> str:
        snippet = self.fact_text[:60] + "…" if len(self.fact_text) > 60 else self.fact_text
        return f"<HistoryFact id={self.id} article_id={self.article_id} {snippet!r}>"


# ── scrape_log ────────────────────────────────────────────────────────────────

class ScrapeLog(Base):
    """Tracks every URL we've seen — prevents re-scraping and allows resuming."""
    __tablename__ = "scrape_log"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    url: Mapped[str] = mapped_column(String(1024), nullable=False, unique=True, index=True)
    source: Mapped[SourceName] = mapped_column(Enum(SourceName), nullable=False)
    status: Mapped[ScrapeStatus] = mapped_column(
        Enum(ScrapeStatus), default=ScrapeStatus.PENDING, nullable=False, index=True
    )
    article_id: Mapped[Optional[int]] = mapped_column(BigInteger, nullable=True)
    error_msg: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    attempts: Mapped[int] = mapped_column(SmallInteger, default=0)
    last_attempted: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
