<?php

declare(strict_types=1);

/**
 * Curated polity → capital mapping for major polities that appear as a lesson's Territory.
 *
 * Keys are the polity's Wikidata QID (matching the corpus `polity:` topics / Cliopatria
 * gazetteer). Each value names the capital city plus that city's own Wikidata QID, so a map
 * feature can resolve a polity to a point even before the cities table carries the polity link.
 *
 * Capitals are the single best-known seat for the polity in lesson context (e.g. the Mongol
 * Empire is mapped to its founding capital Karakorum rather than later Khanbaliq).
 *
 * Consumed by App\Support\PolityCapitals::for().
 *
 * @return array<string, array{city: string, qid: string}>
 */
return [
    // ── Mediterranean & Near Eastern empires ─────────────────────────────────
    'Q12560' => ['city' => 'Istanbul', 'qid' => 'Q406'],         // Ottoman Empire → Constantinople/Istanbul
    'Q12544' => ['city' => 'Rome', 'qid' => 'Q220'],             // Roman Empire → Rome
    'Q175881' => ['city' => 'Rome', 'qid' => 'Q220'],            // Roman Republic → Rome
    'Q42834' => ['city' => 'Ravenna', 'qid' => 'Q13362'],        // Western Roman Empire → Ravenna
    'Q112039853' => ['city' => 'Istanbul', 'qid' => 'Q406'],     // Byzantine Empire → Constantinople
    'Q83958' => ['city' => 'Pella', 'qid' => 'Q201662'],         // Macedonian Empire → Pella
    'Q705904' => ['city' => 'Antakya', 'qid' => 'Q104326'],      // Seleucid Empire → Antioch
    'Q2320005' => ['city' => 'Alexandria', 'qid' => 'Q87'],      // Ptolemaic Kingdom → Alexandria
    'Q2429397' => ['city' => 'Tunis', 'qid' => 'Q3572'],         // Carthage → Carthage (modern Tunis)
    'Q12548' => ['city' => 'Vienna', 'qid' => 'Q1741'],          // Holy Roman Empire → Vienna

    // ── Persia & the caliphates ──────────────────────────────────────────────
    'Q389688' => ['city' => 'Shiraz', 'qid' => 'Q129846'],       // Achaemenid Empire → Persepolis (near Shiraz)
    'Q1661685' => ['city' => 'Baghdad', 'qid' => 'Q205966'],     // Sasanian Empire → Ctesiphon (near Baghdad)
    'Q12490507' => ['city' => 'Medina', 'qid' => 'Q35484'],      // Rashidun Caliphate → Medina
    'Q8575586' => ['city' => 'Damascus', 'qid' => 'Q3766'],      // Umayyad Caliphate → Damascus
    'Q12536' => ['city' => 'Baghdad', 'qid' => 'Q1530'],         // Abbasid Caliphate → Baghdad
    'Q160307' => ['city' => 'Cairo', 'qid' => 'Q85'],            // Fatimid Caliphate → Cairo
    'Q171740' => ['city' => 'Córdoba', 'qid' => 'Q5818'],        // Caliphate of Córdoba → Córdoba

    // ── East, Central & South Asia ───────────────────────────────────────────
    'Q1068371' => ['city' => "Xi'an", 'qid' => 'Q5826'],         // Han Dynasty → Chang'an
    'Q9683' => ['city' => "Xi'an", 'qid' => 'Q5826'],            // Tang Dynasty → Chang'an
    'Q9903' => ['city' => 'Beijing', 'qid' => 'Q956'],           // Ming Dynasty → Beijing
    'Q8733' => ['city' => 'Beijing', 'qid' => 'Q956'],           // Qing Dynasty → Beijing
    'Q12557' => ['city' => 'Karakorum', 'qid' => 'Q282154'],     // Mongol Empire → Karakorum
    'Q33296' => ['city' => 'Delhi', 'qid' => 'Q1353'],           // Mughal Empire → Delhi
    'Q62943' => ['city' => 'Patna', 'qid' => 'Q49159'],          // Maurya Empire → Pataliputra
    'Q11774' => ['city' => 'Patna', 'qid' => 'Q49159'],          // Gupta Empire → Pataliputra
    'Q201705' => ['city' => 'Siem Reap', 'qid' => 'Q43580'],     // Khmer Empire → Angkor

    // ── Africa ───────────────────────────────────────────────────────────────
    'Q180568' => ['city' => 'Luxor', 'qid' => 'Q101583'],        // New Kingdom of Egypt → Thebes
    'Q184536' => ['city' => 'Niani', 'qid' => 'Q3340827'],       // Mali Empire → Niani
    'Q202687' => ['city' => 'Gao', 'qid' => 'Q170739'],          // Songhai Empire → Gao
    'Q722071' => ['city' => 'Kumasi', 'qid' => 'Q200117'],       // Ashanti Empire → Kumasi

    // ── The Americas ─────────────────────────────────────────────────────────
    'Q2608489' => ['city' => 'Mexico City', 'qid' => 'Q1489'],   // Aztec Triple Alliance → Tenochtitlán
    'Q28573' => ['city' => 'Cusco', 'qid' => 'Q5582'],           // Inca Empire → Cusco

    // ── Early-modern European empires ────────────────────────────────────────
    'Q207162' => ['city' => 'Paris', 'qid' => 'Q90'],            // Kingdom of France → Paris
    'Q179876' => ['city' => 'London', 'qid' => 'Q84'],           // Kingdom of England → London
    'Q80702' => ['city' => 'Madrid', 'qid' => 'Q2807'],          // Spanish Empire → Madrid
    'Q200464' => ['city' => 'Lisbon', 'qid' => 'Q597'],          // Portuguese Empire → Lisbon
    'Q34266' => ['city' => 'Saint Petersburg', 'qid' => 'Q656'], // Russian Empire → Saint Petersburg
];
