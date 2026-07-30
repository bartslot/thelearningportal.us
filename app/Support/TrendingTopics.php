<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The five curated cards in the landing page's "Trending history topics" carousel.
 *
 * Single source of truth on purpose. The card headline and the lesson title are NOT always the
 * same string ("Colosseum" on the card, "The Colosseum" as the lesson), and when those two lists
 * lived in separate files two cards silently stopped linking to anything. Keeping the pairing in
 * one place makes that failure impossible to reintroduce.
 */
final class TrendingTopics
{
    /**
     * @return list<array{title: string, lesson: string, category: string, image: string, description: string}>
     */
    public static function cards(): array
    {
        return [
            [
                'title' => 'The Punic Wars',
                'lesson' => 'The Punic Wars',
                'category' => 'Ancient History',
                'image' => 'history/popular1.webp',
                'description' => 'Three devastating wars between Rome and Carthage that shaped the ancient Mediterranean world.',
            ],
            [
                'title' => 'Abraham Lincoln',
                'lesson' => 'Abraham Lincoln',
                'category' => 'American History',
                'image' => 'history/popular2.webp',
                'description' => 'The 16th President who led America through the Civil War and abolished slavery.',
            ],
            [
                'title' => 'Colosseum',
                'lesson' => 'The Colosseum',
                'category' => 'Roman Empire',
                'image' => 'history/popular3.webp',
                'description' => "Rome's iconic amphitheater where gladiators fought before 50,000 spectators.",
            ],
            [
                'title' => 'Gladiators',
                'lesson' => 'Gladiators',
                'category' => 'Roman Culture',
                'image' => 'history/popular4.webp',
                'description' => 'Professional fighters who battled in arenas for entertainment and honor in ancient Rome.',
            ],
            [
                'title' => 'Silk Road',
                'lesson' => 'The Silk Road',
                'category' => 'Trade Routes',
                'image' => 'history/popular5.webp',
                'description' => 'Ancient network of trade routes connecting East and West for over 1,400 years.',
            ],
        ];
    }

    /** Lesson titles to look up, for the landing page query. @return list<string> */
    public static function lessonTitles(): array
    {
        return array_column(self::cards(), 'lesson');
    }
}
