<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Story;
use App\Models\StorySource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Pull a public-domain chapter from Project Gutenberg into the story catalog.
 *
 *   php artisan story:import-gutenberg 18442 --list
 *   php artisan story:import-gutenberg 18442 --chapter="Horatius" --story=horatius-at-the-bridge
 *   php artisan story:import-gutenberg 18442 --chapter=12 --title="The Sword of Damocles"
 *
 * Creates the Story stub (status=draft) when --story doesn't exist yet; run
 * story:draft afterwards to let the LLM propose objectives for human review.
 */
class ImportGutenbergStory extends Command
{
    protected $signature = 'story:import-gutenberg
        {bookId      : Project Gutenberg ebook id, e.g. 18442}
        {--list      : List detected chapters and exit}
        {--chapter=  : Chapter to import — 1-based index or a title fragment}
        {--story=    : Existing story id or slug to attach the excerpt to}
        {--title=    : Title for a NEW story stub (defaults to the chapter heading)}
        {--locale=en : Story locale (nl for Dutch-canon stories)}';

    protected $description = 'Import a Project Gutenberg chapter as a curated story source excerpt.';

    private const MAX_EXCERPT_CHARS = 12000;

    public function handle(): int
    {
        $bookId = (int) $this->argument('bookId');

        $text = $this->fetchBook($bookId);
        if ($text === null) {
            $this->error("Could not download Gutenberg book #{$bookId}.");

            return self::FAILURE;
        }

        [$bookTitle, $author] = $this->parseHeader($text);
        $body = $this->stripBoilerplate($text);
        $chapters = $this->splitChapters($body);

        if ($chapters === []) {
            $this->error('No chapter headings detected — import manually via tinker.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->info("{$bookTitle} — ".($author ?? 'unknown author'));
            foreach ($chapters as $index => $chapter) {
                $words = str_word_count($chapter['text']);
                $this->line(sprintf('%3d. %s (%d words)', $index + 1, $chapter['heading'], $words));
            }

            return self::SUCCESS;
        }

        $chapter = $this->pickChapter($chapters, (string) $this->option('chapter'));
        if ($chapter === null) {
            $this->error('Chapter not found. Run with --list to see options.');

            return self::FAILURE;
        }

        $story = $this->resolveStory($chapter['heading']);

        StorySource::create([
            'story_id' => $story->id,
            'origin' => 'gutenberg',
            'title' => "{$bookTitle} — {$chapter['heading']}",
            'author' => $author,
            'license' => 'Public domain',
            'url' => "https://www.gutenberg.org/ebooks/{$bookId}",
            'excerpt' => Str::limit(trim($chapter['text']), self::MAX_EXCERPT_CHARS, '…'),
            'order' => $story->sources()->count(),
        ]);

        $this->info("Excerpt attached to story '{$story->slug}' (#{$story->id}, status {$story->status->value}).");
        $this->line("Next: php artisan story:draft {$story->slug}");

        return self::SUCCESS;
    }

    private function fetchBook(int $bookId): ?string
    {
        $urls = [
            "https://www.gutenberg.org/cache/epub/{$bookId}/pg{$bookId}.txt",
            "https://www.gutenberg.org/files/{$bookId}/{$bookId}-0.txt",
            "https://www.gutenberg.org/files/{$bookId}/{$bookId}.txt",
        ];

        foreach ($urls as $url) {
            try {
                $response = Http::timeout(30)->get($url);
                if ($response->successful() && strlen($response->body()) > 1000) {
                    // Gutenberg plain-text files ship with CRLF endings — normalize once
                    // so the chapter-splitting regexes can rely on \n.
                    return str_replace(["\r\n", "\r"], "\n", $response->body());
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: ?string} [title, author] */
    private function parseHeader(string $text): array
    {
        $title = preg_match('/^Title:\s*(.+)$/mi', $text, $m) ? trim($m[1]) : 'Gutenberg book';
        $author = preg_match('/^Author:\s*(.+)$/mi', $text, $m) ? trim($m[1]) : null;

        return [$title, $author];
    }

    private function stripBoilerplate(string $text): string
    {
        $text = preg_replace('/^.*?\*\*\* ?START OF (?:THE|THIS) PROJECT GUTENBERG EBOOK.*?\*\*\*/is', '', $text) ?? $text;
        $text = preg_replace('/\*\*\* ?END OF (?:THE|THIS) PROJECT GUTENBERG EBOOK.*$/is', '', $text) ?? $text;

        return trim($text);
    }

    /** @return list<array{heading: string, text: string}> */
    private function splitChapters(string $body): array
    {
        // Headings: ALL-CAPS lines (optionally ending in a period — e.g. "HORATIUS AT THE
        // BRIDGE.") or CHAPTER/ROMAN-NUMERAL markers, surrounded by blank lines.
        $pattern = '/\n{2,}((?:CHAPTER|BOOK|PART)?\s*[IVXLC0-9]*\.?\s*[A-Z][A-Z \'\-,;:]{4,60}\.?)\n{2,}/';
        $parts = preg_split($pattern, "\n\n".$body, flags: PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) < 3) {
            return [];
        }

        $chapters = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $textPart = trim($parts[$i + 1]);
            if (str_word_count($textPart) < 150) {
                continue;   // table-of-contents entries, dedications
            }
            $chapters[] = [
                'heading' => Str::title(trim($parts[$i])),
                'text' => $textPart,
            ];
        }

        return $chapters;
    }

    /** @param list<array{heading: string, text: string}> $chapters */
    private function pickChapter(array $chapters, string $selector): ?array
    {
        if ($selector === '') {
            return null;
        }

        if (ctype_digit($selector)) {
            return $chapters[(int) $selector - 1] ?? null;
        }

        foreach ($chapters as $chapter) {
            if (Str::contains(Str::lower($chapter['heading']), Str::lower($selector))) {
                return $chapter;
            }
        }

        return null;
    }

    private function resolveStory(string $chapterHeading): Story
    {
        $ref = (string) $this->option('story');
        if ($ref !== '') {
            $story = ctype_digit($ref) ? Story::find((int) $ref) : Story::where('slug', $ref)->first();
            if ($story) {
                return $story;
            }
        }

        $title = (string) ($this->option('title') ?: $chapterHeading);
        $slug = $ref !== '' && ! ctype_digit($ref) ? $ref : Str::slug($title);

        return Story::firstOrCreate(
            ['slug' => $slug],
            ['title' => $title, 'locale' => (string) $this->option('locale'), 'status' => 'draft'],
        );
    }
}
