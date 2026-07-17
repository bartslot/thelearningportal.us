<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Str;

final class CanonThemeCatalog
{
    public const OTHER = 'other';

    /**
     * The seven official hoofdlijnen from the Canon van Nederland.
     *
     * @var array<string, array{number:int,label:string,subtitle:string,keywords:array<string,int>}>
     */
    private const THEMES = [
        'vulnerable-delta' => [
            'number' => 1,
            'label' => 'Leven in een kwetsbare delta',
            'subtitle' => 'Nederland waterland',
            'keywords' => [
                'watersnood' => 8, 'waterbeheer' => 7, 'water' => 4, 'delta' => 6,
                'dijk' => 5, 'polder' => 5, 'rivier' => 4, 'zee' => 3,
                'overstroming' => 6, 'inpoldering' => 6, 'droogmakerij' => 6,
                'flood' => 6, 'dyke' => 5, 'river' => 4,
            ],
        ],
        'meaning' => [
            'number' => 2,
            'label' => 'Wat geeft betekenis?',
            'subtitle' => 'Zingeving en levensbeschouwing',
            'keywords' => [
                'levensbeschouwing' => 8, 'religie' => 6, 'geloof' => 5, 'kerk' => 4,
                'christendom' => 6, 'islam' => 6, 'jodendom' => 6, 'reformatie' => 7,
                'willibrord' => 8, 'beeldenstorm' => 6, 'tolerantie' => 5,
                'religion' => 6, 'faith' => 5, 'church' => 4, 'reformation' => 7,
            ],
        ],
        'word-and-image' => [
            'number' => 3,
            'label' => 'Woord en beeld verbinden',
            'subtitle' => 'Taal, kunst en cultuur',
            'keywords' => [
                'woord en beeld' => 9, 'taal' => 5, 'kunst' => 5, 'cultuur' => 5,
                'schilder' => 5, 'schilderkunst' => 7, 'rembrandt' => 8,
                'van gogh' => 8, 'literatuur' => 6, 'boekdruk' => 7,
                'hebban olla vogala' => 9, 'architectuur' => 5, 'media' => 4,
                'language' => 5, 'art' => 5, 'culture' => 5, 'painting' => 6,
            ],
        ],
        'knowledge' => [
            'number' => 4,
            'label' => 'Wat weten wij?',
            'subtitle' => 'Kennis, wetenschap en innovatie',
            'keywords' => [
                'wetenschap' => 7, 'kennis' => 5, 'innovatie' => 7, 'uitvinding' => 6,
                'technologie' => 6, 'microscoop' => 7, 'anatomie' => 6,
                'geneeskunde' => 6, 'onderwijs' => 4, 'techniek' => 5,
                'christiaan huygens' => 9, 'leeuwenhoek' => 9,
                'science' => 7, 'knowledge' => 5, 'invention' => 6, 'technology' => 6,
            ],
        ],
        'inclusion' => [
            'number' => 5,
            'label' => 'Wie telt er mee?',
            'subtitle' => 'Sociale (on)gelijkheid',
            'keywords' => [
                'sociale ongelijkheid' => 9, 'gelijkheid' => 7, 'ongelijkheid' => 7,
                'emancipatie' => 7, 'slavernij' => 8, 'afschaffing' => 6,
                'discriminatie' => 7, 'mensenrechten' => 8, 'vrouwenrechten' => 8,
                'aletta jacobs' => 9, 'arbeiders' => 5, 'kolonialisme' => 5,
                'equality' => 7, 'inequality' => 7, 'rights' => 6, 'slavery' => 8,
                'emancipation' => 7, 'discrimination' => 7,
            ],
        ],
        'governance' => [
            'number' => 6,
            'label' => 'Wie bestuurt er?',
            'subtitle' => 'Politiek en samenleving',
            'keywords' => [
                'tachtigjarige oorlog' => 10, '80 years war' => 10, 'eighty years war' => 10,
                'nederlandse opstand' => 9, 'dutch revolt' => 9, 'willem van oranje' => 9,
                'unie van utrecht' => 9, 'franse revolutie' => 8, 'french revolution' => 8,
                'napoleon' => 8, 'grondwet' => 8, 'wetgeving' => 7, 'democratie' => 7,
                'republiek' => 6, 'bestuur' => 6, 'politiek' => 6, 'overheid' => 5,
                'koning' => 4, 'staat' => 4, 'opstand' => 5, 'spanje' => 4,
                'constitution' => 8, 'legislation' => 7, 'government' => 6,
                'politics' => 6, 'democracy' => 7, 'republic' => 6,
            ],
        ],
        'connections' => [
            'number' => 7,
            'label' => 'Knooppunt van verbindingen',
            'subtitle' => 'Wereldeconomie',
            'keywords' => [
                'wereldeconomie' => 9, 'wereldhandel' => 8, 'handel' => 6,
                'economie' => 5, 'voc' => 8, 'wic' => 8, 'hanze' => 8,
                'scheepvaart' => 6, 'haven' => 5, 'migratie' => 5,
                'globalisering' => 7, 'kooplieden' => 5, 'specerijen' => 5,
                'world economy' => 9, 'trade' => 6, 'economy' => 5,
                'shipping' => 6, 'migration' => 5, 'globalisation' => 7,
            ],
        ],
    ];

    /** @return array<string, array{number:int,label:string,subtitle:string}> */
    public function themes(): array
    {
        return collect(self::THEMES)
            ->map(fn (array $theme): array => [
                'number' => $theme['number'],
                'label' => $theme['label'],
                'subtitle' => $theme['subtitle'],
            ])
            ->all();
    }

    public function isOfficialTheme(string $slug): bool
    {
        return array_key_exists($slug, self::THEMES);
    }

    /**
     * Group lessons by Canon theme for the card grids (Dashboard + Lessons page). Lessons whose
     * stored theme is invalid get a suggestion from their topic text; empty groups are dropped.
     *
     * @param  \Illuminate\Support\Collection<int, Lesson>  $lessons
     * @return list<array{slug:string,number:?int,label:string,subtitle:string,lessons:\Illuminate\Support\Collection<int, Lesson>}>
     */
    public function groupLessons(\Illuminate\Support\Collection $lessons): array
    {
        $definitions = $this->themes();
        $definitions[self::OTHER] = [
            'number' => null,
            'label' => 'Other',
            'subtitle' => 'Topics outside the Canon themes',
        ];

        $grouped = $lessons->groupBy(function (Lesson $lesson): string {
            $theme = (string) $lesson->canon_theme;

            if ($this->isValidTheme($theme)) {
                return $theme;
            }

            return $this->suggest($lesson->topic, $lesson->details, $lesson->focus);
        });

        return collect($definitions)
            ->map(fn (array $definition, string $slug): array => [
                'slug' => $slug,
                'number' => $definition['number'],
                'label' => $definition['label'],
                'subtitle' => $definition['subtitle'],
                'lessons' => $grouped->get($slug, collect()),
            ])
            ->filter(fn (array $group): bool => $group['lessons']->isNotEmpty())
            ->values()
            ->all();
    }

    public function isValidTheme(string $slug): bool
    {
        return $slug === self::OTHER || $this->isOfficialTheme($slug);
    }

    public function isDutchTeacher(?User $teacher): bool
    {
        return $teacher !== null && Str::startsWith((string) $teacher->locale, 'nl');
    }

    public function suggest(?string ...$parts): string
    {
        $text = Str::lower(implode(' ', array_filter($parts, fn (?string $part): bool => filled($part))));
        if ($text === '') {
            return self::OTHER;
        }

        $scores = [];
        foreach (self::THEMES as $slug => $theme) {
            $scores[$slug] = collect($theme['keywords'])
                ->map(fn (int $weight, string $keyword): int => str_contains($text, $keyword) ? $weight : 0)
                ->sum();
        }

        arsort($scores);
        $suggested = array_key_first($scores);

        return $suggested !== null && ($scores[$suggested] ?? 0) >= 4
            ? $suggested
            : self::OTHER;
    }

    /**
     * @return array<string, array{
     *     number:int,
     *     label:string,
     *     subtitle:string,
     *     lesson_count:int,
     *     taught_count:int,
     *     status:string
     * }>
     */
    public function coverageFor(User $teacher): array
    {
        $coverage = collect($this->themes())
            ->map(fn (array $theme): array => [
                ...$theme,
                'lesson_count' => 0,
                'taught_count' => 0,
                'status' => 'untaught',
            ])
            ->all();

        $lessons = Lesson::query()
            ->where('teacher_id', $teacher->id)
            ->select([
                'id',
                'canon_theme',
                'topic',
                'details',
                'focus',
            ])
            ->withCount(['classrooms', 'studentProgress', 'quizScores'])
            ->get();

        foreach ($lessons as $lesson) {
            $slug = $this->isOfficialTheme((string) $lesson->canon_theme)
                ? (string) $lesson->canon_theme
                : $this->suggest($lesson->topic, $lesson->details, $lesson->focus);

            if (! $this->isOfficialTheme($slug)) {
                continue;
            }

            $coverage[$slug]['lesson_count']++;

            if (
                $lesson->classrooms_count > 0
                || $lesson->student_progress_count > 0
                || $lesson->quiz_scores_count > 0
            ) {
                $coverage[$slug]['taught_count']++;
            }
        }

        foreach ($coverage as &$theme) {
            $theme['status'] = match (true) {
                $theme['taught_count'] > 0 => 'taught',
                $theme['lesson_count'] > 0 => 'prepared',
                default => 'untaught',
            };
        }

        return $coverage;
    }
}
