"""
Generate embedding vectors for semantic search (pgvector).

Backends (set EMBED_BACKEND in .env):
  ollama        — nomic-embed-text via Ollama   (free, offline, 768-dim) ← default
  lmstudio      — any embedding model via LM Studio's OpenAI-compat API  (free, offline)
  openai-compat — any OpenAI-compatible server  (vLLM, Groq, Together AI, etc.)
  openai        — OpenAI text-embedding-3-small (~$0.30 for 15K articles, 1536-dim)

LM Studio notes:
  - Load an embedding model in LM Studio alongside your chat model
  - Good free options: nomic-embed-text, all-minilm-l6-v2, bge-small-en-v1.5
  - LM Studio serves at http://localhost:1234/v1/embeddings (same as OpenAI format)
  - Set EMBED_MODEL to the exact model identifier shown in LM Studio
  - Set EMBED_DIM to match the model (nomic-embed-text=768, all-minilm=384, bge-small=384)

The embedding input is: article title + first sentence of summary (max 512 chars).
Stored in history_articles.embedding (Vector column, dimension = EMBED_DIM).
"""
from __future__ import annotations

import logging

import httpx
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from ..config import cfg
from ..models import HistoryArticle, ScrapeStatus

logger = logging.getLogger(__name__)

BATCH_SIZE = 20   # embed N articles at a time (reduce if LM Studio is slow)


# ── Ollama embedding (free, offline) ─────────────────────────────────────────

async def _embed_ollama(texts: list[str]) -> list[list[float]]:
    """
    Call Ollama's /api/embed endpoint.
    Recommended model: nomic-embed-text (fast, 768-dim, great quality).
    Pull first: `ollama pull nomic-embed-text`
    """
    model = cfg.EMBED_MODEL or "nomic-embed-text"
    async with httpx.AsyncClient(timeout=120.0) as client:
        resp = await client.post(
            f"{cfg.OLLAMA_URL}/api/embed",
            json={"model": model, "input": texts},
        )
        resp.raise_for_status()
        data = resp.json()
        # Ollama returns {"embeddings": [[...], [...]]}
        return data.get("embeddings", [])


# ── LM Studio / OpenAI-compatible embedding (free, offline) ──────────────────

async def _embed_openai_compat(texts: list[str]) -> list[list[float]]:
    """
    Call any OpenAI-compatible /v1/embeddings endpoint.

    Works with:
      - LM Studio  (http://localhost:1234/v1)
      - vLLM       (http://localhost:8000/v1)
      - Groq, Together AI, etc. (set LLM_API_KEY too)

    LM Studio setup:
      1. Open LM Studio → Developers tab → Start server
      2. Load an embedding model (e.g. nomic-embed-text, bge-small-en-v1.5)
      3. Set EMBED_MODEL to the model identifier shown in LM Studio
      4. Set LLM_BASE_URL=http://localhost:1234/v1 in .env
    """
    base_url = (cfg.LLM_BASE_URL or "http://localhost:1234/v1").rstrip("/")
    model    = cfg.EMBED_MODEL or "nomic-embed-text"

    headers  = {"Content-Type": "application/json"}
    if cfg.LLM_API_KEY:
        headers["Authorization"] = f"Bearer {cfg.LLM_API_KEY}"

    # OpenAI-compatible servers accept single string or list
    async with httpx.AsyncClient(timeout=120.0) as client:
        resp = await client.post(
            f"{base_url}/embeddings",
            headers=headers,
            json={"model": model, "input": texts},
        )
        resp.raise_for_status()
        data = resp.json()

    # OpenAI format: {"data": [{"embedding": [...], "index": 0}, ...]}
    if "data" in data:
        ordered = sorted(data["data"], key=lambda x: x.get("index", 0))
        return [item["embedding"] for item in ordered]

    # Some servers (e.g. older LM Studio) return {"embeddings": [...]}
    if "embeddings" in data:
        return data["embeddings"]

    raise ValueError(f"Unexpected embedding response shape: {list(data.keys())}")


# ── OpenAI cloud embedding (paid) ─────────────────────────────────────────────

async def _embed_openai_cloud(texts: list[str]) -> list[list[float]]:
    """
    Call OpenAI Embeddings API (text-embedding-3-small, 1536-dim).
    Costs ~$0.30 for a full 15K-article corpus.

    NOTE: If you switch to this backend, update EMBED_DIM=1536
    and recreate the Supabase HNSW index (see supabase-setup.sql comments).
    """
    try:
        import openai  # type: ignore
    except ImportError:
        raise RuntimeError("pip install openai to use EMBED_BACKEND=openai")
    model  = cfg.EMBED_MODEL or "text-embedding-3-small"
    client = openai.AsyncOpenAI(api_key=cfg.OPENAI_API_KEY)
    resp   = await client.embeddings.create(model=model, input=texts)
    return [item.embedding for item in resp.data]


# ── Router ────────────────────────────────────────────────────────────────────

async def _embed(texts: list[str]) -> list[list[float]]:
    backend = (cfg.EMBED_BACKEND or "ollama").lower()
    if backend == "ollama":
        return await _embed_ollama(texts)
    elif backend in ("lmstudio", "openai-compat", "openai_compat", "vllm", "local"):
        return await _embed_openai_compat(texts)
    elif backend == "openai":
        return await _embed_openai_cloud(texts)
    else:
        raise ValueError(
            f"Unknown EMBED_BACKEND: {backend!r}. "
            "Use: ollama | lmstudio | openai-compat | openai"
        )


# ── Batch processor ───────────────────────────────────────────────────────────

async def run_embeddings(
    session: AsyncSession,
    limit: int = 0,
) -> int:
    """
    Generate embeddings for all EXTRACTED articles that don't have a vector yet.
    Returns the number of articles embedded.
    """
    embedded = 0

    while True:
        result = await session.execute(
            select(HistoryArticle)
            .where(
                HistoryArticle.status == ScrapeStatus.EXTRACTED,
                HistoryArticle.embedding.is_(None),
            )
            .limit(BATCH_SIZE)
        )
        articles = result.scalars().all()
        if not articles:
            break

        # Embedding input: title + summary (capped at 512 chars for speed)
        texts = [
            f"{art.title}. {art.summary or ''}".strip()[:512]
            for art in articles
        ]

        try:
            vectors = await _embed(texts)
        except Exception as exc:
            logger.error("[Embeddings] Backend error: %s", exc)
            break

        if len(vectors) != len(articles):
            logger.warning(
                "[Embeddings] Vector count mismatch: got %d for %d articles",
                len(vectors), len(articles)
            )
            break

        for art, vec in zip(articles, vectors):
            art.embedding = vec
            art.status    = ScrapeStatus.EMBEDDED

        await session.flush()
        await session.commit()
        embedded += len(articles)
        logger.info("[Embeddings] ✓ %d embedded (total=%d)", len(articles), embedded)

        if limit and embedded >= limit:
            break

    logger.info("[Embeddings] Done. total=%d", embedded)
    return embedded
