<?php

declare(strict_types=1);

namespace App\Lessons;

/**
 * Focus tags catalog: a curated set of thematic angles teachers can apply to lessons.
 * Tags are ONE source of truth — both chat and wizard flows read from here.
 * Each tag is a (slug, label) pair; teachers can select up to 3 tags.
 */
final class FocusTags
{
    private const MAX_TAGS = 3;

    /**
     * @return array<string, array{label: string}> keyed by slug, ordered
     */
    public static function all(): array
    {
        return [
            'power' => ['label' => 'Power'],
            'war' => ['label' => 'War'],
            'revolution' => ['label' => 'Revolution'],
            'rights' => ['label' => 'Rights'],
            'religion' => ['label' => 'Religion'],
            'ideas' => ['label' => 'Ideas'],
            'art-culture' => ['label' => 'Art & Culture'],
            'trade' => ['label' => 'Trade'],
            'empire' => ['label' => 'Empire'],
            'exploration' => ['label' => 'Exploration'],
            'slavery' => ['label' => 'Slavery'],
            'innovation' => ['label' => 'Innovation'],
            'society' => ['label' => 'Society'],
            'migration' => ['label' => 'Migration'],
            'disease' => ['label' => 'Disease'],
            'environment' => ['label' => 'Environment'],
        ];
    }

    /**
     * Whitelist, deduplicate, and cap slugs at MAX_TAGS.
     * Unknown slugs are silently dropped.
     *
     * @param  array<string>  $slugs
     * @return array<string>  unique, valid slugs (values only, values-keyed)
     */
    public static function sanitize(array $slugs): array
    {
        $all = self::all();

        return array_values(
            array_unique(
                array_filter(
                    $slugs,
                    fn (string $slug): bool => isset($all[$slug])
                )
            )
        );
    }

    /**
     * Cap at MAX_TAGS, keeping first N.
     *
     * @param  array<string>  $slugs  valid slugs only (post-sanitize)
     * @return array<string>
     */
    public static function cap(array $slugs): array
    {
        return array_slice($slugs, 0, self::MAX_TAGS);
    }

    /**
     * Toggle semantics for the UI chips: tapping a selected tag removes it;
     * adding past MAX_TAGS is a no-op (returns the selection unchanged).
     *
     * @param  array<string>  $current
     * @return array<string>
     */
    public static function toggle(array $current, string $slug): array
    {
        if (in_array($slug, $current, true)) {
            return array_values(array_diff($current, [$slug]));
        }

        return self::cap(self::sanitize([...$current, $slug]));
    }

    /**
     * English labels for a set of slugs, comma-separated.
     * Used in generation prompts (English only).
     *
     * @param  array<string>  $slugs
     */
    public static function labels(array $slugs): string
    {
        $all = self::all();

        return implode(', ', array_filter(array_map(
            fn (string $slug): string => $all[$slug]['label'] ?? '',
            $slugs
        )));
    }
}
