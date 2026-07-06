<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Story;
use Illuminate\Database\Seeder;

/**
 * First batch of catalog stories, Dutch pilot first: ~20 vensters from the Canon van
 * Nederland (the official Dutch K-12 history canon — a ready-made "limited set of
 * stories" teachers already teach to) plus a few world-history staples for demos.
 *
 * These are STUBS (status=draft): metadata + narrative shape. The founder workflow adds
 * real source excerpts (story:import-gutenberg / manual) and LLM-drafted pedagogy
 * (story:draft) before human review publishes them to teachers.
 */
class CanonStoriesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::stories() as $attributes) {
            Story::updateOrCreate(['slug' => $attributes['slug']], $attributes);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function stories(): array
    {
        $nl = fn (array $extra) => array_merge([
            'locale' => 'nl',
            'region' => 'Europe',
            'status' => 'draft',
        ], $extra);

        return [
            // ── Canon van Nederland vensters (selection) ──────────────────────
            $nl(['slug' => 'hunebedden', 'title' => 'Hunebedden', 'subtitle' => 'De eerste boeren en hun stenen graven', 'era_start' => -3400, 'era_end' => -2850, 'grade_band' => '3-5', 'narrative_framework' => 'mystery']),
            $nl(['slug' => 'romeinse-limes', 'title' => 'De Romeinse Limes', 'subtitle' => 'Soldaten aan de rand van het rijk', 'era_start' => 47, 'era_end' => 400, 'grade_band' => '3-5', 'narrative_framework' => 'heros_journey']),
            $nl(['slug' => 'willibrord', 'title' => 'Willibrord', 'subtitle' => 'Een monnik steekt de zee over', 'era_start' => 690, 'era_end' => 739, 'grade_band' => '3-5', 'narrative_framework' => 'heros_journey', 'protagonist_name' => 'Willibrord']),
            $nl(['slug' => 'hebban-olla-vogala', 'title' => 'Hebban olla vogala', 'subtitle' => 'De oudste Nederlandse zin', 'era_start' => 1100, 'era_end' => 1100, 'grade_band' => '3-5', 'narrative_framework' => 'mystery']),
            $nl(['slug' => 'floris-v', 'title' => 'Floris V', 'subtitle' => 'De graaf die de boeren koos — en het met de dood bekocht', 'era_start' => 1254, 'era_end' => 1296, 'grade_band' => '6-8', 'narrative_framework' => 'rise_and_fall', 'protagonist_name' => 'Floris V']),
            $nl(['slug' => 'de-hanze', 'title' => 'De Hanze', 'subtitle' => 'Steden die samen rijk werden', 'era_start' => 1250, 'era_end' => 1550, 'grade_band' => '6-8', 'narrative_framework' => 'rise_and_fall']),
            $nl(['slug' => 'willem-van-oranje', 'title' => 'Willem van Oranje', 'subtitle' => 'Van vertrouweling van de koning tot vader van de opstand', 'era_start' => 1533, 'era_end' => 1584, 'grade_band' => '6-8', 'narrative_framework' => 'heros_journey', 'protagonist_name' => 'Willem van Oranje']),
            $nl(['slug' => 'de-beeldenstorm', 'title' => 'De Beeldenstorm', 'subtitle' => 'De zomer waarin de kerken leeg werden geslagen', 'era_start' => 1566, 'era_end' => 1566, 'grade_band' => '6-8', 'narrative_framework' => 'in_medias_res']),
            $nl(['slug' => 'de-voc', 'title' => 'De VOC', 'subtitle' => 'Het eerste wereldbedrijf — glorie en schaduw', 'era_start' => 1602, 'era_end' => 1799, 'grade_band' => '6-8', 'narrative_framework' => 'rise_and_fall']),
            $nl(['slug' => 'rembrandt', 'title' => 'Rembrandt', 'subtitle' => 'De schilder van licht en schaduw', 'era_start' => 1606, 'era_end' => 1669, 'grade_band' => '6-8', 'narrative_framework' => 'rise_and_fall', 'protagonist_name' => 'Rembrandt van Rijn']),
            $nl(['slug' => 'michiel-de-ruyter', 'title' => 'Michiel de Ruyter', 'subtitle' => 'De scheepsjongen die admiraal werd', 'era_start' => 1607, 'era_end' => 1676, 'grade_band' => '3-5', 'narrative_framework' => 'heros_journey', 'protagonist_name' => 'Michiel de Ruyter']),
            $nl(['slug' => 'rampjaar-1672', 'title' => 'Het Rampjaar 1672', 'subtitle' => 'Het jaar waarin het land reddeloos leek', 'era_start' => 1672, 'era_end' => 1673, 'grade_band' => '9-12', 'narrative_framework' => 'in_medias_res']),
            $nl(['slug' => 'slavernij-en-afschaffing', 'title' => 'Slavernij en afschaffing', 'subtitle' => 'De mensen achter de handel — en de lange weg naar 1863', 'era_start' => 1621, 'era_end' => 1863, 'grade_band' => '9-12', 'narrative_framework' => 'mystery']),
            $nl(['slug' => 'napoleon-in-nederland', 'title' => 'Napoleon en Nederland', 'subtitle' => 'Hoe een keizer ons land hertekende', 'era_start' => 1795, 'era_end' => 1813, 'grade_band' => '6-8', 'narrative_framework' => 'rise_and_fall', 'protagonist_name' => 'Napoleon Bonaparte']),
            $nl(['slug' => 'aletta-jacobs', 'title' => 'Aletta Jacobs', 'subtitle' => 'Het meisje dat dokter wilde worden toen dat niet mocht', 'era_start' => 1854, 'era_end' => 1929, 'grade_band' => '6-8', 'narrative_framework' => 'heros_journey', 'protagonist_name' => 'Aletta Jacobs']),
            $nl(['slug' => 'eerste-wereldoorlog-nl', 'title' => 'Nederland in de Eerste Wereldoorlog', 'subtitle' => 'Neutraal tussen de vuurlinies', 'era_start' => 1914, 'era_end' => 1918, 'grade_band' => '9-12', 'narrative_framework' => 'branching']),
            $nl(['slug' => 'anne-frank', 'title' => 'Anne Frank', 'subtitle' => 'Een dagboek uit het Achterhuis', 'era_start' => 1942, 'era_end' => 1945, 'grade_band' => '6-8', 'narrative_framework' => 'in_medias_res', 'protagonist_name' => 'Anne Frank']),
            $nl(['slug' => 'hongerwinter', 'title' => 'De Hongerwinter', 'subtitle' => 'De laatste, koudste oorlogswinter', 'era_start' => 1944, 'era_end' => 1945, 'grade_band' => '6-8', 'narrative_framework' => 'in_medias_res']),
            $nl(['slug' => 'indonesie-onafhankelijk', 'title' => 'Indonesië onafhankelijk', 'subtitle' => 'Het einde van Nederlands-Indië', 'era_start' => 1945, 'era_end' => 1949, 'grade_band' => '9-12', 'narrative_framework' => 'branching']),
            $nl(['slug' => 'watersnood-1953', 'title' => 'De Watersnood van 1953', 'subtitle' => 'De nacht dat het water kwam', 'era_start' => 1953, 'era_end' => 1953, 'grade_band' => '3-5', 'narrative_framework' => 'in_medias_res']),

            // ── World-history staples (demo + non-Dutch pilots) ───────────────
            ['slug' => 'julius-caesar-ides', 'title' => 'Julius Caesar and the Ides of March', 'subtitle' => 'The friend who held the knife', 'locale' => 'en', 'region' => 'Europe', 'era_start' => -49, 'era_end' => -44, 'grade_band' => '6-8', 'narrative_framework' => 'rise_and_fall', 'protagonist_name' => 'Julius Caesar', 'status' => 'draft'],
            ['slug' => 'horatius-at-the-bridge', 'title' => 'Horatius at the Bridge', 'subtitle' => 'One soldier against an army', 'locale' => 'en', 'region' => 'Europe', 'era_start' => -509, 'era_end' => -509, 'grade_band' => '3-5', 'narrative_framework' => 'heros_journey', 'protagonist_name' => 'Horatius Cocles', 'status' => 'draft'],
            ['slug' => 'cleopatra-last-pharaoh', 'title' => 'Cleopatra, the Last Pharaoh', 'subtitle' => 'The queen who gambled on Rome', 'locale' => 'en', 'region' => 'Africa', 'era_start' => -51, 'era_end' => -30, 'grade_band' => '6-8', 'narrative_framework' => 'rise_and_fall', 'protagonist_name' => 'Cleopatra VII', 'status' => 'draft'],
            ['slug' => 'trojan-horse', 'title' => 'The Trojan Horse', 'subtitle' => 'The trick that ended a ten-year war', 'locale' => 'en', 'region' => 'Europe', 'era_start' => -1200, 'era_end' => -1180, 'grade_band' => '3-5', 'narrative_framework' => 'mystery', 'protagonist_name' => 'Odysseus', 'status' => 'draft'],
        ];
    }
}
