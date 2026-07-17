<?php

declare(strict_types=1);

namespace App\Services\StoryImages;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Maps a free-text subject (a scraped caption like "Ubbo Emmius") to a Wikidata
 * entity QID, and exposes that entity's canonical image (P18).
 *
 * This is the accuracy backbone: resolving to the ENTITY lets us fetch the subject's
 * real portrait / a structurally-"depicts" image, instead of hoping a Commons
 * full-text search lands on the right file (which turned "Ubbo Emmius" into a photo
 * of a street named after him).
 */
final class WikidataEntityResolver
{
    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com)';
    private const TIMEOUT = 12;
    private const CACHE_TTL = 86400;   // 24h

    /** Best-match entity QID for a subject phrase, in the caption's language. */
    public function qidFor(string $text, string $lang = 'en'): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        return Cache::remember('wd.qid.'.$lang.'.'.md5($text), self::CACHE_TTL, function () use ($text, $lang) {
            $hit = $this->client()->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbsearchentities',
                'search' => mb_substr($text, 0, 200),
                'language' => $lang,
                'uselang' => $lang,
                'type' => 'item',
                'format' => 'json',
                'limit' => 1,
            ])->json('search.0.id');

            return is_string($hit) ? $hit : null;
        });
    }

    /** The entity's canonical Commons image filename (P18), or null. */
    public function imageFile(string $qid): ?string
    {
        return Cache::remember('wd.p18.'.$qid, self::CACHE_TTL, function () use ($qid) {
            $claims = $this->client()
                ->get("https://www.wikidata.org/wiki/Special:EntityData/{$qid}.json")
                ->json("entities.{$qid}.claims.P18", []);

            $file = $claims[0]['mainsnak']['datavalue']['value'] ?? null;

            return is_string($file) ? $file : null;
        });
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withUserAgent(self::UA)->timeout(self::TIMEOUT);
    }
}
