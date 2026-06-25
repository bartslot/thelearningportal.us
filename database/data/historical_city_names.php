<?php

declare(strict_types=1);

/**
 * Curated historical names for major cities that recur in history lessons.
 *
 * One iconic historical name per modern city (the single most lesson-relevant one), so each
 * `modern` value is unique. `qid` is the Wikidata QID of the modern city. Periods are
 * approximate teaching ranges.
 *
 * Consumed by Database\Seeders\HistoricalCityNamesSeeder, which matches each `modern`
 * against cities.name (ILIKE) and fills historical_name / historical_period / wikidata_qid.
 * When Natural Earth has no matching city, the seeder creates a stub row (lat/lng 0).
 *
 * @return array<int, array{modern: string, historical: string, period: string, qid: string}>
 */
return [
    // ── Ancient Near East & Mediterranean ────────────────────────────────────
    ['modern' => 'Istanbul', 'historical' => 'Constantinople', 'period' => '330–1453 CE', 'qid' => 'Q406'],
    ['modern' => 'Rome', 'historical' => 'Roma', 'period' => '753 BCE–476 CE', 'qid' => 'Q220'],
    ['modern' => 'Athens', 'historical' => 'Athenai', 'period' => '5th c. BCE', 'qid' => 'Q1524'],
    ['modern' => 'Alexandria', 'historical' => 'Alexandria ad Aegyptum', 'period' => '331 BCE–642 CE', 'qid' => 'Q87'],
    ['modern' => 'Jerusalem', 'historical' => 'Aelia Capitolina', 'period' => '130–325 CE', 'qid' => 'Q1218'],
    ['modern' => 'Damascus', 'historical' => 'Dimashqu', 'period' => 'antiquity', 'qid' => 'Q3766'],
    ['modern' => 'İzmir', 'historical' => 'Smyrna', 'period' => 'antiquity–1922 CE', 'qid' => 'Q35997'],
    ['modern' => 'Naples', 'historical' => 'Neapolis', 'period' => 'antiquity', 'qid' => 'Q2634'],
    ['modern' => 'Marseille', 'historical' => 'Massalia', 'period' => '600 BCE onward', 'qid' => 'Q23482'],
    ['modern' => 'Tunis', 'historical' => 'Carthage', 'period' => '814–146 BCE', 'qid' => 'Q3572'],

    // ── Mesopotamia, Persia & Central Asia ───────────────────────────────────
    ['modern' => 'Baghdad', 'historical' => 'Madinat al-Salam', 'period' => '762–1258 CE', 'qid' => 'Q1530'],
    ['modern' => 'Hillah', 'historical' => 'Babylon', 'period' => '1894–539 BCE', 'qid' => 'Q221413'],
    ['modern' => 'Mosul', 'historical' => 'Nineveh', 'period' => '7th c. BCE', 'qid' => 'Q35539'],
    ['modern' => 'Shiraz', 'historical' => 'Persepolis', 'period' => '550–330 BCE', 'qid' => 'Q129846'],
    ['modern' => 'Samarkand', 'historical' => 'Maracanda', 'period' => 'antiquity–Timurid', 'qid' => 'Q5753'],
    ['modern' => 'Bukhara', 'historical' => 'Bukhārā', 'period' => 'Silk Road era', 'qid' => 'Q5712'],
    ['modern' => 'Ctesiphon', 'historical' => 'Ctesiphon', 'period' => '120 BCE–637 CE', 'qid' => 'Q205966'],
    ['modern' => 'Merv', 'historical' => 'Margiana', 'period' => 'antiquity–1221 CE', 'qid' => 'Q623578'],

    // ── Egypt & Africa ───────────────────────────────────────────────────────
    ['modern' => 'Cairo', 'historical' => 'Fustat', 'period' => '641–1168 CE', 'qid' => 'Q85'],
    ['modern' => 'Luxor', 'historical' => 'Thebes', 'period' => '2055–1070 BCE', 'qid' => 'Q101583'],
    ['modern' => 'Memphis', 'historical' => 'Memphis', 'period' => '3100–641 CE', 'qid' => 'Q5715'],
    ['modern' => 'Timbuktu', 'historical' => 'Timbuktu', 'period' => '13th–16th c. CE', 'qid' => 'Q11936'],
    ['modern' => 'Fez', 'historical' => 'Fes el-Bali', 'period' => '789 CE onward', 'qid' => 'Q31525'],
    ['modern' => 'Masvingo', 'historical' => 'Great Zimbabwe', 'period' => '1100–1450 CE', 'qid' => 'Q175482'],

    // ── Europe ───────────────────────────────────────────────────────────────
    ['modern' => 'Paris', 'historical' => 'Lutetia', 'period' => 'Roman Gaul', 'qid' => 'Q90'],
    ['modern' => 'London', 'historical' => 'Londinium', 'period' => '47–410 CE', 'qid' => 'Q84'],
    ['modern' => 'Cologne', 'historical' => 'Colonia Agrippina', 'period' => 'Roman era', 'qid' => 'Q365'],
    ['modern' => 'Vienna', 'historical' => 'Vindobona', 'period' => 'Roman era', 'qid' => 'Q1741'],
    ['modern' => 'Lisbon', 'historical' => 'Olisipo', 'period' => 'Roman era', 'qid' => 'Q597'],
    ['modern' => 'Córdoba', 'historical' => 'Qurtuba', 'period' => '756–1031 CE', 'qid' => 'Q5818'],
    ['modern' => 'Seville', 'historical' => 'Hispalis', 'period' => 'Roman–Moorish', 'qid' => 'Q8717'],
    ['modern' => 'York', 'historical' => 'Eboracum', 'period' => '71–410 CE', 'qid' => 'Q42462'],
    ['modern' => 'Saint Petersburg', 'historical' => 'Leningrad', 'period' => '1924–1991 CE', 'qid' => 'Q656'],
    ['modern' => 'Volgograd', 'historical' => 'Stalingrad', 'period' => '1925–1961 CE', 'qid' => 'Q914'],

    // ── East & South Asia ────────────────────────────────────────────────────
    ['modern' => 'Beijing', 'historical' => 'Khanbaliq', 'period' => '1264–1368 CE', 'qid' => 'Q956'],
    ["modern" => "Xi'an", 'historical' => "Chang'an", 'period' => '202 BCE–907 CE', 'qid' => 'Q5826'],
    ['modern' => 'Nanjing', 'historical' => 'Jinling', 'period' => 'Six Dynasties', 'qid' => 'Q16666'],
    ['modern' => 'Luoyang', 'historical' => 'Luoyi', 'period' => 'Eastern Zhou–Han', 'qid' => 'Q92099'],
    ['modern' => 'Tokyo', 'historical' => 'Edo', 'period' => '1603–1868 CE', 'qid' => 'Q1490'],
    ['modern' => 'Kyoto', 'historical' => 'Heian-kyō', 'period' => '794–1869 CE', 'qid' => 'Q34600'],
    ['modern' => 'Nara', 'historical' => 'Heijō-kyō', 'period' => '710–784 CE', 'qid' => 'Q183234'],
    ['modern' => 'Hanoi', 'historical' => 'Thang Long', 'period' => '1010–1802 CE', 'qid' => 'Q1858'],
    ['modern' => 'Ho Chi Minh City', 'historical' => 'Saigon', 'period' => '1698–1976 CE', 'qid' => 'Q1854'],
    ['modern' => 'Delhi', 'historical' => 'Shahjahanabad', 'period' => '1639–1857 CE', 'qid' => 'Q1353'],
    ['modern' => 'Patna', 'historical' => 'Pataliputra', 'period' => '490 BCE–550 CE', 'qid' => 'Q49159'],
    ['modern' => 'Varanasi', 'historical' => 'Kashi', 'period' => 'antiquity', 'qid' => 'Q1001'],
    ['modern' => 'Kolkata', 'historical' => 'Calcutta', 'period' => '1690–2001 CE', 'qid' => 'Q1348'],
    ['modern' => 'Mumbai', 'historical' => 'Bombay', 'period' => '1661–1995 CE', 'qid' => 'Q1156'],
    ['modern' => 'Siem Reap', 'historical' => 'Angkor', 'period' => '802–1431 CE', 'qid' => 'Q43580'],

    // ── The Americas ─────────────────────────────────────────────────────────
    ['modern' => 'Mexico City', 'historical' => 'Tenochtitlán', 'period' => '1325–1521 CE', 'qid' => 'Q1489'],
    ['modern' => 'Cusco', 'historical' => 'Qosqo', 'period' => '1200–1572 CE', 'qid' => 'Q5582'],
    ['modern' => 'New York', 'historical' => 'New Amsterdam', 'period' => '1625–1664 CE', 'qid' => 'Q60'],
    ['modern' => 'Cahokia', 'historical' => 'Cahokia', 'period' => '1050–1350 CE', 'qid' => 'Q1023474'],
];
