<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Corpus\Topic;
use Illuminate\Console\Command;

/**
 * Read-only audit. Reports catalog topics whose name shares no significant word with the
 * Wikipedia article they ground in — candidate QID-mapping mislabels inherited from Cliopatria
 * (e.g. "Roman Empire" -> Byzantine_Empire, "Dutch Republic" -> Kingdom_of_France).
 *
 * Most hits are legitimate aliases (Russian Federation -> Russia, Burma -> Myanmar); a human
 * reviews the list to find the genuinely wrong QID mappings. This command NEVER writes.
 */
final class AuditTopicUrls extends Command
{
    protected $signature = 'corpus:audit-topic-urls {--limit=400 : How many of the most-famous topics to scan}';

    protected $description = 'Report catalog topics whose name shares no significant word with their grounded Wikipedia article (candidate mislabels). Read-only.';

    /** Words that legitimately differ between an official name and a Wikipedia article title. */
    private const STOPWORDS = [
        'empire', 'kingdom', 'republic', 'of', 'and', 'the', 'dynasty', 'state', 'states',
        'federal', 'federated', 'federation', 'commonwealth', 'union', 'principality',
        'people', 'ancient', 'city',
    ];

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $rows = Topic::resilient(fn () => Topic::whereNotNull('wikipedia_url')
            ->orderByDesc('sitelinks')
            ->limit($limit)
            ->get(['name', 'wikipedia_url', 'sitelinks']));

        $flagged = [];
        foreach ($rows as $topic) {
            $article = self::articleTitle((string) $topic->wikipedia_url);
            if (! self::sharesSignificantWord((string) $topic->name, $article)) {
                $flagged[] = [$topic->name, $article];
            }
        }

        if ($flagged === []) {
            $this->info("No name/article divergences in the top {$limit} topics.");

            return self::SUCCESS;
        }

        $this->warn(count($flagged).' topics share no significant word with their article. '
            .'Most are legitimate aliases; review for genuine QID mislabels (e.g. Roman Empire -> Byzantine_Empire):');
        $this->table(['Catalog name', 'Grounded article'], $flagged);

        return self::SUCCESS;
    }

    /** The decoded article title (underscores preserved) from a Wikipedia URL. */
    public static function articleTitle(string $url): string
    {
        return urldecode(basename((string) parse_url($url, PHP_URL_PATH)));
    }

    /** Do the name and article share at least one non-stopword token (case-insensitive)? */
    public static function sharesSignificantWord(string $name, string $article): bool
    {
        $tokenize = static fn (string $value): array => array_values(array_diff(
            array_filter(explode(' ', strtolower(str_replace(['_', '-', "'"], ' ', $value)))),
            self::STOPWORDS,
        ));

        return array_intersect($tokenize($name), $tokenize($article)) !== [];
    }
}
