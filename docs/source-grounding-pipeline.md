# Source-Grounding Pipeline (RAG) — Design

**Goal:** stop generating lessons from whatever the LLM scrapes off Wikipedia, and instead
ground every script in a **pre-indexed corpus of vetted, mostly public-domain historian texts
and primary sources**. Catalog metadata (Open Library / WorldCat / OCLC) is used to *discover
and vet* sources; the *content* fed to the LLM comes from full-text public-domain works.

## Design principles

1. **Index ahead of time, retrieve fast.** All the expensive work — downloading, cleaning,
   chunking, embedding — happens **offline** in a batch ingestion pipeline. Nothing hits an
   external catalog or fetches a book during lesson generation.
2. **One embed + one search at runtime.** At generation time the pipeline does exactly two
   cheap operations: embed the query once, run one pre-filtered vector search against the
   local DB. No live API hops, no per-lesson network calls to OCLC/Gutenberg.
3. **Metadata filter first, vectors second.** Every chunk carries denormalised metadata
   (historical figure, era, grade band). We filter to a few hundred candidate chunks by
   metadata *before* doing cosine similarity, so search stays milliseconds even on SiteGround
   shared MySQL.
4. **No hallucination (reinforces CLAUDE.md).** The LLM only ever sees retrieved passages and
   is instructed to use nothing else. Every generated claim is traceable to a `source_chunk`.

## Two-phase architecture

```
┌─ PHASE 1: OFFLINE INGESTION (batch, run rarely) ───────────────────────────┐
│                                                                            │
│  Open Library / WorldCat API    Gutenberg / Perseus / Internet Archive     │
│  (metadata: what to trust)      (full text: what to feed the LLM)          │
│         │                                │                                 │
│         ▼                                ▼                                 │
│  sources:discover  ──►  sources:fetch  ──►  sources:chunk  ──►  sources:embed
│  (Source rows)          (raw text)          (SourceChunk rows) (+ embeddings)│
│                                                                            │
│  Result: a fully embedded, queryable corpus sitting in your own DB.        │
└────────────────────────────────────────────────────────────────────────────┘

┌─ PHASE 2: RUNTIME RETRIEVAL (per lesson, fast) ────────────────────────────┐
│                                                                            │
│  GenerateLesson job                                                        │
│     │                                                                      │
│     ▼                                                                      │
│  RetrievalService::forLesson($lesson)                                      │
│     ├─ EmbeddingService::embed($query)      ← 1 hop (local Ollama / OpenAI)│
│     └─ vector search over source_chunks     ← 1 DB query (metadata-filtered)│
│     ▼                                                                      │
│  grounded context + citations  ──►  LlmService::generateScript(...)        │
│     ▼                                                                      │
│  Lesson script + Source[] citations attached                              │
└────────────────────────────────────────────────────────────────────────────┘
```

This drops in exactly at the `WikipediaService → LlmService` boundary in your existing
`GenerateLesson` job. `WikipediaService::fetchFacts()` is replaced (or augmented) by
`RetrievalService::forLesson()`.

---

## 1. Data model

Two tables. `sources` is one row per work; `source_chunks` is one row per embeddable passage.
Metadata is denormalised onto the chunk so retrieval filtering never needs a join at scale.

### Migration — `sources`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('provider');                 // SourceProvider enum value
            $table->string('source_type');              // SourceType enum value
            $table->string('status')->default('discovered'); // SourceStatus enum value

            // Catalog identifiers (from Open Library / WorldCat / OCLC)
            $table->string('openlibrary_id')->nullable()->index();
            $table->string('oclc_number')->nullable()->index();
            $table->string('external_id')->nullable();  // Gutenberg id, Perseus urn, IA id
            $table->string('source_url')->nullable();

            // Vetting + licensing
            $table->string('license')->default('public_domain');
            $table->unsignedSmallInteger('published_year')->nullable();
            $table->json('subjects')->nullable();        // LCSH / FAST headings

            // Curriculum targeting (drives fast metadata pre-filter)
            $table->string('historical_figure')->nullable()->index();
            $table->string('era')->nullable()->index();
            $table->unsignedTinyInteger('grade_min')->default(1);
            $table->unsignedTinyInteger('grade_max')->default(12);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'external_id']); // dedupe on re-ingest
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
```

### Migration — `source_chunks`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('source_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->string('locator')->nullable();       // e.g. "Book I, ch. 3" for citations
            $table->unsignedSmallInteger('token_count')->default(0);

            // Embedding stored portably as a packed float32 blob (see config).
            $table->binary('embedding')->nullable();
            $table->string('embedding_model')->nullable();

            // Denormalised from source for filter-first retrieval (no join at query time).
            $table->string('historical_figure')->nullable()->index();
            $table->string('era')->nullable()->index();
            $table->unsignedTinyInteger('grade_min')->default(1);
            $table->unsignedTinyInteger('grade_max')->default(12);

            $table->timestamps();

            $table->index(['historical_figure', 'era']); // composite pre-filter
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_chunks');
    }
};
```

### Enums (`app/Enums/`)

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum SourceProvider: string
{
    case OpenLibrary    = 'openlibrary';   // metadata (free API)
    case WorldCat       = 'worldcat';      // metadata (OCLC — gated, optional)
    case Gutenberg      = 'gutenberg';     // full text (public domain)
    case Perseus        = 'perseus';       // full text (classics — great for Caesar)
    case InternetArchive = 'archive';      // full text (public domain scans)
}

enum SourceType: string
{
    case Primary   = 'primary';    // e.g. Caesar's own Commentaries
    case Secondary = 'secondary';  // historian narrative (public domain)
    case Reference = 'reference';
}

enum SourceStatus: string
{
    case Discovered = 'discovered'; // metadata row exists, no text yet
    case Fetched    = 'fetched';    // raw full text downloaded
    case Chunked    = 'chunked';    // split into source_chunks
    case Indexed    = 'indexed';    // chunks embedded — ready for retrieval
    case Skipped    = 'skipped';    // not public domain / unusable
}
```

### Models (`app/Models/`)

```php
<?php
declare(strict_types=1);
namespace App\Models;

use App\Enums\SourceProvider;
use App\Enums\SourceStatus;
use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Source extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'provider'    => SourceProvider::class,
        'source_type' => SourceType::class,
        'status'      => SourceStatus::class,
        'subjects'    => 'array',
    ];

    public function chunks() { return $this->hasMany(SourceChunk::class); }
}

class SourceChunk extends Model
{
    protected $guarded = [];

    public function source() { return $this->belongsTo(Source::class); }

    /** Decode packed float32 blob → float[] */
    public function vector(): array
    {
        return $this->embedding
            ? array_values(unpack('g*', $this->embedding)) // 'g' = little-endian float32
            : [];
    }

    /** Encode float[] → packed float32 blob */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }
}
```

---

## 2. Config (`config/corpus.php`)

```php
<?php
declare(strict_types=1);

return [
    // Embedding must be identical at ingest time and query time.
    'embedding' => [
        'driver'     => env('EMBED_DRIVER', 'ollama'),        // ollama | openai
        'model'      => env('EMBED_MODEL', 'nomic-embed-text'), // 768-dim, free, local
        'dimensions' => (int) env('EMBED_DIMENSIONS', 768),
        'ollama_url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'batch_size' => 64,                                    // embed in batches — fewer hops
    ],

    'chunk' => [
        'max_tokens' => 400,   // passage size
        'overlap'    => 60,    // sliding-window overlap
    ],

    'retrieval' => [
        'top_k'         => 8,     // passages fed to the LLM
        'min_score'     => 0.25,  // drop weak matches
        'candidate_cap' => 800,   // max chunks loaded before cosine (metadata-filtered)
    ],
];
```

Production swap in `.env`: `EMBED_DRIVER=openai`, `EMBED_MODEL=text-embedding-3-small`,
`EMBED_DIMENSIONS=1536`. (Re-embed the corpus once when you change models.)

---

## 3. Offline ingestion pipeline

Four artisan commands, each idempotent, run in sequence. Heavy work is queued so it survives
SiteGround's `queue:work`-via-cron setup.

### `EmbeddingService` (shared by ingest + retrieval)

```php
<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\Http;

class EmbeddingService
{
    /** @param string[] $texts  @return float[][] */
    public function embedBatch(array $texts): array
    {
        return config('corpus.embedding.driver') === 'openai'
            ? $this->openai($texts)
            : $this->ollama($texts);
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    private function ollama(array $texts): array
    {
        $out = [];
        foreach ($texts as $t) {                 // Ollama embeds one at a time
            $res = Http::baseUrl(config('corpus.embedding.ollama_url'))
                ->post('/api/embeddings', [
                    'model'  => config('corpus.embedding.model'),
                    'prompt' => $t,
                ])->throw()->json();
            $out[] = $res['embedding'];
        }
        return $out;
    }

    private function openai(array $texts): array
    {
        $res = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => config('corpus.embedding.model'),
                'input' => $texts,               // OpenAI embeds the whole batch in 1 hop
            ])->throw()->json();
        return array_map(fn ($d) => $d['embedding'], $res['data']);
    }
}
```

### Command 1 — discover (metadata: Open Library / WorldCat)

```php
// app/Console/Commands/SourcesDiscover.php  — sources:discover {topic} {--figure=} {--era=}
public function handle(OpenLibraryService $catalog): int
{
    foreach ($catalog->searchPublicDomain($this->argument('topic')) as $meta) {
        Source::updateOrCreate(
            ['provider' => $meta->provider, 'external_id' => $meta->externalId],
            [
                'title'             => $meta->title,
                'author'            => $meta->author,
                'source_type'      => $meta->type,
                'openlibrary_id'    => $meta->openLibraryId,
                'oclc_number'       => $meta->oclc,
                'subjects'          => $meta->subjects,
                'published_year'    => $meta->year,
                'source_url'        => $meta->fullTextUrl,
                'historical_figure' => $this->option('figure'),
                'era'               => $this->option('era'),
                'status'            => SourceStatus::Discovered,
            ],
        );
    }
    return self::SUCCESS;
}
```

`OpenLibraryService::searchPublicDomain()` hits `https://openlibrary.org/search.json`, keeps
only records with an Internet Archive / public-domain full-text link, and carries the OCLC
number through for cross-referencing. (WorldCat is optional and gated — Open Library is free
and covers the same metadata.)

### Command 2 — fetch (full text)

`sources:fetch` pulls raw text for every `Discovered` source from its provider
(Gutenberg plaintext, Perseus TEI/XML, Internet Archive `_djvu.txt`), stores it under
`storage/app/corpus/{source_id}.txt`, and sets status → `Fetched`. Skips anything without a
clean public-domain text.

### Command 3 — chunk

`sources:chunk` cleans boilerplate (Gutenberg headers/licenses, XML tags), splits into
~400-token windows with 60-token overlap, writes `SourceChunk` rows with the `locator`
(e.g. "Gallic War, Book I, ch. 3") and copies down `historical_figure` / `era` / grade band.
Status → `Chunked`.

### Command 4 — embed

```php
// app/Console/Commands/SourcesEmbed.php  — sources:embed
public function handle(EmbeddingService $embedder): int
{
    SourceChunk::whereNull('embedding')->chunkById(
        config('corpus.embedding.batch_size'),
        function ($chunks) use ($embedder) {
            $vectors = $embedder->embedBatch($chunks->pluck('content')->all());
            foreach ($chunks as $i => $chunk) {
                $chunk->update([
                    'embedding'       => SourceChunk::pack($vectors[$i]),
                    'embedding_model' => config('corpus.embedding.model'),
                ]);
            }
        },
    );
    Source::where('status', SourceStatus::Chunked)->update(['status' => SourceStatus::Indexed]);
    return self::SUCCESS;
}
```

After this runs once, the corpus is fully indexed and self-contained. Re-run only when you
add new sources.

---

## 4. Runtime retrieval (fast path)

### `VectorMath`

```php
<?php
declare(strict_types=1);
namespace App\Support;

class VectorMath
{
    public static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
            $na  += $v * $v;
            $nb  += $b[$i] * $b[$i];
        }
        return ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
```

### `RetrievalService`

```php
<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Lesson;
use App\Models\SourceChunk;
use App\Support\VectorMath;

class RetrievalService
{
    public function __construct(private EmbeddingService $embedder) {}

    /** @return SourceChunk[]  top-k grounded passages with source relation loaded */
    public function forLesson(Lesson $lesson): array
    {
        // 1 hop: embed the query once.
        $query  = "{$lesson->topic}. {$lesson->historical_figure}. Grade {$lesson->grade_level}.";
        $qVec   = $this->embedder->embed($query);

        // 1 DB query: metadata pre-filter shrinks the search to a few hundred candidates.
        $candidates = SourceChunk::query()
            ->with('source:id,title,author,source_url,license')
            ->when($lesson->historical_figure,
                fn ($q) => $q->where('historical_figure', $lesson->historical_figure))
            ->where('grade_min', '<=', $lesson->grade_level)
            ->where('grade_max', '>=', $lesson->grade_level)
            ->whereNotNull('embedding')
            ->limit(config('corpus.retrieval.candidate_cap'))
            ->get();

        // In-memory cosine over the small candidate set — sub-second, no extra hops.
        $scored = $candidates
            ->map(fn ($c) => ['chunk' => $c, 'score' => VectorMath::cosine($qVec, $c->vector())])
            ->filter(fn ($r) => $r['score'] >= config('corpus.retrieval.min_score'))
            ->sortByDesc('score')
            ->take(config('corpus.retrieval.top_k'));

        return $scored->pluck('chunk')->all();
    }
}
```

### Feed it to the LLM (`LlmService` change)

The LLM returns raw text with **inline span markers** — this is what makes individual
dates, quotes, and claims clickable. Each marker names the exact chunk it came from:

```php
/**
 * Returns script text containing inline span markers, e.g.
 *   [[quote|chunk=3128|loc=Book I, §32|the die is cast]]
 * A parser (§5) turns each marker into a clickable, source-linked segment.
 */
public function generateScript(array $chunks, array $params): string
{
    $sources = collect($chunks)->map(fn ($c) =>
        "[chunk={$c->id}] {$c->source->title} — {$c->source->author} ({$c->locator}):\n{$c->content}"
    )->implode("\n\n");

    $system = <<<PROMPT
    You are a historian writing a dramatic, grade-{$params['grade']} lesson script.
    Use ONLY the source chunks below. Every date, quotation and factual claim MUST be
    wrapped in a span marker that names its chunk:

        [[KIND|chunk=ID|loc=LOCATOR|the exact narrative text]]

    KIND is one of: date, quote, claim. LOCATOR is a human citation (e.g. "Book I, §32").
    Copy the cited phrase verbatim inside the marker. If the sources do not cover
    something, omit it — never invent, and never emit a marker without a real chunk id.
    PROMPT;

    // ... call Ollama / Claude Haiku with $system + $sources + $params → returns marked text ...
}
```

### Plug into the existing `GenerateLesson` job

```php
// Before:
//   $facts  = app(WikipediaService::class)->fetchFacts($lesson->topic);
//   $script = app(LlmService::class)->generateScript($facts, $params);

// After:
$chunks   = app(RetrievalService::class)->forLesson($lesson);      // pre-indexed corpus, 2 ops
$marked   = app(LlmService::class)->generateScript($chunks, $params);
$segments = app(ScriptParser::class)->parse($marked, $chunks);     // §5 — clickable spans

$lesson->update(['script' => $marked, 'script_segments' => $segments]);
$lesson->sources()->sync(collect($segments)->pluck('source_id')->filter()->unique());
```

---

## 5. Clickable span-level citations (hover + scroll-to-passage)

This is the layer that turns the marked script into the interaction you want: a coloured
underline on each date/quote/claim, a hover label naming the source, and a click that jumps
to the **exact phrase** — both in an in-app corpus reader *and* on the original open resource.

### 5.1 Store segments on the lesson

```php
// migration: add to lessons
$table->json('script_segments')->nullable()->after('script');
```

A segment is either plain narrative or a cited span:

```json
[
  { "text": "The winter road north of Rome was quiet on the night of " },
  { "text": "10 January 49 BC", "kind": "date", "source_id": 12, "chunk_id": 3128,
    "locator": "Book I, §31", "phrase": "10 January 49 BC" },
  { "text": ", when Caesar reached the banks of the Rubicon." }
]
```

### 5.2 Parse markers → segments (`ScriptParser`)

```php
<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\SourceChunk;
use Illuminate\Support\Str;

class ScriptParser
{
    /** @param SourceChunk[] $chunks  @return array<int,array<string,mixed>> */
    public function parse(string $marked, array $chunks): array
    {
        $byId = collect($chunks)->keyBy('id');
        $segments = [];
        // [[kind|chunk=ID|loc=LOCATOR|phrase]]
        $pattern = '/\[\[(date|quote|claim)\|chunk=(\d+)\|loc=([^|]*)\|(.+?)\]\]/s';
        $offset = 0;

        preg_match_all($pattern, $marked, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $hit) {
            [$full, $pos] = $hit[0];
            if ($pos > $offset) {
                $segments[] = ['text' => substr($marked, $offset, $pos - $offset)];
            }
            $chunk  = $byId->get((int) $hit[2][0]);
            $phrase = $hit[4][0];

            // Verification pass — only keep the marker if the phrase is really in the chunk.
            $verified = $chunk && $this->supports($chunk->content, $phrase);
            $segments[] = $verified
                ? [
                    'text'      => $phrase,
                    'kind'      => $hit[1][0],
                    'source_id' => $chunk->source_id,
                    'chunk_id'  => $chunk->id,
                    'locator'   => $hit[3][0] ?: $chunk->locator,
                    'phrase'    => $phrase,
                  ]
                : ['text' => $phrase]; // demote unverifiable spans to plain text — never fake a citation
            $offset = $pos + strlen($full);
        }
        if ($offset < strlen($marked)) {
            $segments[] = ['text' => substr($marked, $offset)];
        }
        return $segments;
    }

    /** Loose containment check (case/space-insensitive) so minor rewording still verifies. */
    private function supports(string $haystack, string $phrase): bool
    {
        $norm = fn ($s) => Str::of($s)->lower()->squish()->toString();
        return str_contains($norm($haystack), $norm($phrase));
    }
}
```

The verification pass is the safety net for your "no hallucination" rule: a span only ships as
a citation if its text actually appears in the cited chunk; otherwise it silently becomes plain
narrative — never a wrong-colour underline pointing at a source that doesn't support it.

### 5.3 Resolve both links (`CitationLinkResolver`)

Each cited span gets two destinations. The scroll-to-phrase trick is the browser
**Text Fragments** syntax (`#:~:text=`), which scrolls to and highlights the phrase on the
original page; the in-app reader does the same with a highlight for a fully controlled view.

```php
<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\SourceChunk;

class CitationLinkResolver
{
    /** @return array{internal:string, external:?string} */
    public function links(SourceChunk $chunk, string $phrase): array
    {
        // In-app reader, scrolled to the exact chunk + phrase (JS highlights it).
        $internal = route('corpus.read', $chunk->source_id)
            . '#c' . $chunk->chunk_index
            . '?q=' . rawurlencode($phrase);

        // Original open resource with a Text Fragment anchor (Chromium/Safari scroll+highlight).
        $external = $chunk->source->source_url
            ? $chunk->source->source_url . '#:~:text=' . rawurlencode($this->trim($phrase))
            : null;

        return ['internal' => $internal, 'external' => $external];
    }

    // Text Fragments match best on short, exact strings — cap very long phrases.
    private function trim(string $p): string
    {
        return \Illuminate\Support\Str::words($p, 12, '');
    }
}
```

### 5.4 In-app corpus reader (scroll + highlight)

```php
// routes/web.php
Route::get('/corpus/sources/{source}', [CorpusReaderController::class, 'show'])
    ->name('corpus.read');
```

The view renders the full cleaned source text with each chunk wrapped in an anchor id, then a
tiny script scrolls to the requested chunk and highlights the `?q=` phrase — the `#`-link
behaviour you described, but reliable across browsers:

```blade
{{-- resources/views/corpus/read.blade.php --}}
<article class="prose max-w-2xl mx-auto p-6">
  @foreach ($source->chunks as $chunk)
    <span id="c{{ $chunk->chunk_index }}" class="corpus-chunk">{{ $chunk->content }}</span>
  @endforeach
</article>

<script>
  const q = new URLSearchParams(location.hash.split('?')[1] || '').get('q');
  const target = document.querySelector(location.hash.split('?')[0]) // #c{index}
              || document.querySelector('.corpus-chunk');
  if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
  if (q && target) {
    target.innerHTML = target.textContent.replace(
      q, m => `<mark class="bg-warning/40 rounded px-0.5">${m}</mark>`
    );
  }
</script>
```

### 5.5 Render the script (Livewire + Alpine + DaisyUI)

One Blade partial turns segments into hover-underlined, clickable spans. Colour by `kind`
via DaisyUI theme tokens; the tooltip names the source; the popover offers both links.

```blade
{{-- resources/views/livewire/lesson-script.blade.php --}}
<div x-data="{ mode: 'read' }">
  <div class="tabs tabs-boxed w-fit mb-4">
    <a class="tab" :class="mode==='read' && 'tab-active'" @click="mode='read'">Reading view</a>
    <a class="tab" :class="mode==='sources' && 'tab-active'" @click="mode='sources'">Sources view</a>
  </div>

  <p class="leading-loose" :class="mode==='sources' && 'show-sources'">
    @foreach ($lesson->script_segments as $seg)
      @if (empty($seg['source_id']))
        {{ $seg['text'] }}
      @else
        @php $links = app(CitationLinkResolver::class)
              ->links(App\Models\SourceChunk::find($seg['chunk_id']), $seg['phrase']); @endphp
        <span class="cite cite-{{ $seg['kind'] }} tooltip"
              data-tip="{{ $seg['locator'] }}"
              x-data="{ open: false }" @click="open = !open">{{ $seg['text'] }}
          <span x-show="open" x-cloak class="card card-compact absolute z-10 bg-base-100 shadow p-3 mt-1">
            <a href="{{ $links['internal'] }}" class="link link-primary">Read in lesson corpus</a>
            @if ($links['external'])
              <a href="{{ $links['external'] }}" target="_blank" rel="noopener"
                 class="link">Open original source ↗</a>
            @endif
          </span>
        </span>
      @endif
    @endforeach
  </p>
</div>
```

```css
/* underlines use theme tokens — hidden until hover in reading view, always shown in sources view */
.cite { border-bottom: 2px solid transparent; cursor: pointer; transition: border-color .12s; }
.cite-date:hover,  .show-sources .cite-date  { border-bottom-color: var(--color-warning); }
.cite-quote:hover, .show-sources .cite-quote { border-bottom-color: var(--color-primary); }
.cite-claim:hover, .show-sources .cite-claim { border-bottom-color: var(--color-success); }
```

---

## 6. Vector index options (pick per environment)

The default above (metadata pre-filter → in-memory cosine) is deliberately dependency-free and
runs fine on SiteGround shared MySQL, because the pre-filter keeps the candidate set to
hundreds of chunks. If the corpus grows past ~100k chunks *per figure*, upgrade the search
step without changing anything else:

| Environment | Approach | Notes |
|---|---|---|
| **Local dev (SQLite)** | `sqlite-vec` extension (`vec0` virtual table) | True ANN search; drop-in for `RetrievalService`'s search step. |
| **Prod (MySQL, SiteGround)** | Metadata pre-filter + in-memory cosine (default) | Zero extra infra; fast while corpus stays curriculum-bounded. |
| **If you can move DB** | Postgres + `pgvector` (`<=>` operator) | Best long-term; one SQL query does filter + ANN together. |

The retrieval *interface* (`RetrievalService::forLesson`) stays identical — only its internals
change — so you can start with the default and upgrade later with no caller changes.

---

## 7. Seed corpus — index a lot immediately

These are free, public-domain, bulk-ingestable, and cover history broadly. Run
`sources:discover` + fetch/chunk/embed against them to fill the corpus fast.

**Broad history (bulk):**
- Project Gutenberg — the "History" bookshelf (thousands of titles; bulk catalog at
  `gutenberg.org/ebooks/bookshelf`). Plaintext download per book.
- Internet Archive — public-domain history collections (`_djvu.txt` per scan).
- Standard Ebooks — cleanly formatted public-domain classics (good text quality).
- HathiTrust — public-domain subset.

**Primary + classical (deep, quotable, all public domain):**
- Perseus Digital Library — Caesar's *Gallic War* & *Civil War* (his own words = `primary`).
- Plutarch, *Lives* — Gutenberg / Perseus.
- Suetonius, *The Twelve Caesars* — Gutenberg.
- Cassius Dio, *Roman History* — Perseus / LacusCurtius.

**Paintings / imagery (same provenance model as text):**
- Met Open Access, Rijksmuseum, Wikimedia Commons, Europeana — public-domain historical
  artwork with a stable source URL + license per image, so every painting shown is clickable
  back to its museum record exactly like a cited sentence.

**Metadata / discovery layer:**
- Open Library search API (free) — dedupe and vet; carries OCLC numbers.
- WorldCat/OCLC — optional, only if you get API access; not required.

A single topic can reach thousands of high-quality, quotable chunks from Gutenberg + Perseus
alone — enough that any lesson script can be built entirely from real, citable source text.

---

## 8. Rollout

```bash
php artisan make:migration create_sources_table
php artisan make:migration create_source_chunks_table
php artisan make:migration add_script_segments_to_lessons_table   # §5 clickable spans
php artisan migrate

# One-time bulk index (queued — safe under SiteGround cron + queue:work)
php artisan sources:discover "Julius Caesar" --era="Roman Republic"
php artisan sources:fetch
php artisan sources:chunk
php artisan sources:embed

# Add the reader route (routes/web.php) → name('corpus.read') for scroll-to-passage links.
# From here on, GenerateLesson uses RetrievalService — no Wikipedia, no live catalog hops.
```

**Net effect:** lessons are grounded in named, citable, historian- and primary-source text;
every date, quote, and claim carries a coloured underline that a teacher can hover to see the
source and click to jump to the exact passage — both inside the app and on the original open
resource; and generation stays fast because every expensive step already happened at index time.
