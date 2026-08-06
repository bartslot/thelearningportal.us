<?php

declare(strict_types=1);

/**
 * The VOC — the world's first multinational, and what it cost.
 *
 * Uses the animated Brouwer Route voyage map (catalogue id brouwer-route) for the sea legs, so
 * students watch the Eendracht actually sail the "highway of wind". The lesson deliberately gives
 * the violence and the coerced labour their own scenes: a Canon lesson about the VOC that only
 * celebrates the profit is not honest history.
 */
return [
    'key' => 'The VOC: the world\'s first multinational',
    'title' => 'The VOC: the world\'s first multinational',
    'subject' => 'history',
    'language' => 'nl',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [

        [
            'type' => 'quiz',
            'when' => 'pre',
            'chapter' => 'What do you already know?',
            'questions' => [
                [
                    'q' => 'What do the letters VOC stand for?',
                    'o' => ['Verenigde Oostindische Compagnie', 'Vrije Overzeese Colonie', 'Volkskamer Oost Compagnie', 'Verenigde Oorlogs Commissie'],
                    'c' => 0,
                    'e' => 'The United East India Company, founded in 1602.',
                ],
                [
                    'q' => 'Which goods made the VOC rich?',
                    'o' => ['Coal and iron', 'Spices such as pepper, nutmeg and cloves', 'Wool and linen', 'Gold and silver mines'],
                    'c' => 1,
                    'e' => 'Spices from the East Indies were worth more than their weight in silver in Europe.',
                ],
                [
                    'q' => 'The VOC was the first company in the world to do something we still do today. What?',
                    'o' => ['Pay wages in cash', 'Sell shares to the public', 'Use paper receipts', 'Advertise in newspapers'],
                    'c' => 1,
                    'e' => 'Anyone could buy a share in the VOC — the beginning of the modern stock market.',
                ],
                [
                    'q' => 'Where was the VOC\'s Asian headquarters?',
                    'o' => ['Malacca', 'Batavia, on Java', 'Nagasaki', 'Colombo'],
                    'c' => 1,
                    'e' => 'Batavia — today Jakarta, the capital of Indonesia.',
                ],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'A company with the powers of a state',
            'location' => 'Amsterdam',
            'year' => 1602,
            'image' => 'commons:Een aantal Oost-Indiëvaarders voor de kust, SK-A-3108.jpg',
            'prefer' => 'art',
            'script' => 'In 1602 the Dutch Republic did something no country had done before. It forced its quarrelling spice merchants to merge into a single company: the Verenigde Oostindische Compagnie, the VOC. Then it gave that company powers that normally belong only to a government. The VOC could raise armies, wage war, sign treaties with foreign rulers, build forts and mint its own coins. And to raise the money, it sold shares to ordinary citizens, who could buy and sell them on the Amsterdam exchange. It was the first company in the world to work that way, and we still copy it today.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'Why spices?',
            'location' => 'The Spice Islands',
            'year' => 1600,
            'title' => 'Worth more than silver',
            'date_label' => 'around 1600',
            'story' => 'It is hard to imagine now, but pepper, nutmeg, mace and cloves were once among the most valuable substances on earth. They preserved food, they masked the taste of meat that was past its best, they were used as medicine, and they were a way for rich Europeans to show off. Nutmeg and mace grew on just a handful of tiny volcanic islands in the Banda archipelago, and nowhere else in the world. A sack of nutmeg bought in Banda could be sold in Amsterdam for a fortune. That single fact — a rare plant growing in one small place, wanted desperately far away — shaped two centuries of Dutch history.',
            'images' => [
                'nutmeg Myristica fragrans botanical illustration',
                'Banda Islands map 17th century',
                'kruidnagel clove botanical plate',
            ],
            'prefer' => 'art',
            'script' => 'Pepper, nutmeg, mace and cloves were once among the most valuable substances on earth. They preserved food, they were used as medicine, and they showed that you were rich. Nutmeg grew on a handful of tiny volcanic islands in the Banda archipelago, and nowhere else in the world. A sack bought there could be sold in Amsterdam for a fortune. That single fact shaped two centuries of Dutch history.',
        ],

        [
            'type' => '3d',
            'chapter' => 'The ship: the Eendracht',
            'location' => 'The Eendracht',
            'year' => 1616,
            'sketchfab' => 'https://sketchfab.com/3d-models/eendracht-5122ceb52cfd4bf5be518fdf693efbb3',
            'script' => 'This is an East Indiaman, the workhorse of the VOC. Turn it around and look at how it is built: fat and deep in the belly to carry cargo, high at the stern, and armed, because a ship like this had to be its own warship. Around two hundred men lived on board for months at a time. The Eendracht sailed for the Indies in 1616 under Dirk Hartog, and she is about to make an accidental discovery that will change the map of the world.',
        ],

        // ── The voyage itself, on the animated route map ──────────────────
        [
            'type' => 'voyage',
            'voyage' => 'brouwer-route',
            'leg' => 0,
            'intro' => true,
            'chapter' => 'Leaving Texel',
            'location' => 'Texel',
            'year' => 1616,
            'title' => 'Departure',
            'date_label' => 'January 1616',
            'story' => 'On 23 January 1616 the Eendracht slipped out of the roadstead of Texel, the deep-water anchorage where the VOC fleets gathered. Her skipper carried sealed instructions built on a discovery made five years earlier by a captain named Hendrik Brouwer.',
            'images' => ['Texel rede schepen 17e eeuw', 'VOC retourschip'],
            'prefer' => 'art',
            'script' => 'On the twenty third of January 1616, the Eendracht slipped out of the roadstead of Texel, the deep water anchorage where the VOC fleets gathered before an ocean crossing. Her skipper carried sealed instructions built on a discovery made five years earlier by a captain named Hendrik Brouwer.',
        ],
        [
            'type' => 'voyage',
            'voyage' => 'brouwer-route',
            'leg' => 1,
            'chapter' => 'The Cape',
            'location' => 'Cape of Good Hope',
            'year' => 1616,
            'title' => 'The refreshment station',
            'date_label' => 'August 1616',
            'story' => 'Six months out, the fleet reached the Cape of Good Hope. Here the VOC would later plant its famous refreshment station, where scurvy-ridden crews took on fresh water and greens before the hardest part of the voyage. The Cape was the hinge of the whole route.',
            'images' => ['Kaap de Goede Hoop 17e eeuw', 'Tafelberg Kaapstad schip'],
            'prefer' => 'art',
            'script' => 'Six months out, the fleet reached the Cape of Good Hope at the southern tip of Africa. Here the VOC would later plant its famous refreshment station, where sick crews took on fresh water, meat and greens before the hardest part of the voyage. The Cape was the hinge of the whole route.',
        ],
        [
            'type' => 'voyage',
            'voyage' => 'brouwer-route',
            'leg' => 2,
            'chapter' => 'The Roaring Forties',
            'location' => 'The Roaring Forties',
            'year' => 1616,
            'title' => 'Brouwer\'s highway of wind',
            'date_label' => 'September 1616',
            'story' => 'Instead of steering north-east toward India, VOC ships dropped south to about forty degrees latitude and let the ferocious westerly winds hurl them across the empty Southern Ocean. Cold, wild and lonely — but fast. The route cut the journey from over a year to roughly six months, and saved thousands of lives.',
            'images' => ['storm op zee 17e eeuw schilderij', 'zeilschip zware zee'],
            'prefer' => 'art',
            'script' => 'This was Brouwer\'s genius. Instead of steering north east toward India, the ships dropped south to around forty degrees and let the ferocious westerly winds of the Roaring Forties hurl them across the empty Southern Ocean. It was cold, wild and lonely, but it was fast: six months instead of a year, and thousands of lives saved. There was only one catch. Out here a captain could measure how far north or south he was, but he had no reliable way of knowing how far EAST he had come.',
        ],
        [
            'type' => 'voyage',
            'voyage' => 'brouwer-route',
            'leg' => 3,
            'chapter' => 'An accidental continent',
            'location' => 'Dirk Hartog Island',
            'year' => 1616,
            'title' => 'The pewter plate',
            'date_label' => '25 October 1616',
            'story' => 'Turn north too late, and you hit a coast no chart showed. On 25 October 1616 that is exactly what happened. Dirk Hartog struck land off Western Australia, flattened a pewter plate, wrote his name and date on it, and nailed it to a post. It is the oldest known European artefact in Australia.',
            'images' => ['Hartog plate 1616', 'Dirk Hartog Island'],
            'prefer' => 'photo',
            'script' => 'Every skipper faced the same anxious guess: turn north too late, and you would slam into a coast no chart yet showed. On the twenty fifth of October 1616, that is exactly what happened to the Eendracht. Dirk Hartog struck land off Western Australia, flattened a pewter plate, scratched his name and the date onto it, and nailed it to a post. It is the oldest known European object in Australia. He had no idea he had brushed the edge of a continent. He noted the danger, turned north, and sailed on.',
        ],
        [
            'type' => 'voyage',
            'voyage' => 'brouwer-route',
            'leg' => 4,
            'chapter' => 'Batavia',
            'location' => 'Batavia',
            'year' => 1616,
            'title' => 'The heart of the empire',
            'date_label' => 'December 1616',
            'story' => 'Through the Sunda Strait the fleet reached the roadstead that became Batavia — today Jakarta — the VOC\'s fortified Asian capital. From here the company ran a trading network stretching from the Cape to Japan.',
            'images' => ['Batavia Jakarta 17e eeuw stad', 'kasteel Batavia VOC'],
            'prefer' => 'art',
            'script' => 'Threading the Sunda Strait between Sumatra and Java, the fleet finally reached the roadstead that would become Batavia — today Jakarta — the VOC\'s fortified Asian capital. From here the company ran a trading network that stretched from the Cape of Good Hope all the way to Japan.',
        ],

        // ── The cost ─────────────────────────────────────────────────────
        [
            'type' => 'story',
            'chapter' => 'Banda, 1621',
            'location' => 'Banda Islands',
            'year' => 1621,
            'image' => 'commons:Jan Pieterszoon Coen by Jacob Waben.jpg',
            'prefer' => 'art',
            'script' => 'To control the nutmeg trade completely, the VOC decided it was not enough to trade with the Bandanese. In 1621 the governor general, Jan Pieterszoon Coen, brought soldiers and mercenaries to the Banda Islands. In a matter of months, a population of roughly fifteen thousand people was almost entirely destroyed — killed, starved, driven out or enslaved. A few hundred survived. The islands were then handed to Dutch planters, who worked them with enslaved labour. This was not an accident of war. It was a business decision, and it is one of the darkest chapters in Dutch history.',
        ],
        [
            'type' => 'gallery',
            'chapter' => 'Who actually did the work',
            'location' => 'The Indies',
            'year' => 1700,
            'title' => 'The people behind the profit',
            'date_label' => '17th – 18th century',
            'story' => 'The wealth that paid for the canal houses of Amsterdam was not produced in Amsterdam. It was produced by people in Asia and Africa, most of whom had no choice in the matter. The VOC traded in enslaved people as well as spices, moving tens of thousands of men, women and children around its Asian empire to work on plantations, in households and at the docks. Dutch sailors died in enormous numbers too — of roughly a million people who sailed east for the VOC, only about a third ever came home. When you look at a golden-age painting of a merchant in his fine dark coat, this is the other half of the picture.',
            'images' => [
                'Batavia kasteel gezicht',
                'VOC pakhuis Amsterdam',
                'zeeman 17e eeuw schilderij',
            ],
            'prefer' => 'art',
            'script' => 'The wealth that paid for the canal houses of Amsterdam was not produced in Amsterdam. It was produced by people in Asia and Africa, most of whom had no choice. The VOC traded in enslaved people as well as spices, moving tens of thousands of men, women and children around its Asian empire. Dutch sailors died in huge numbers too: of roughly a million people who sailed east for the company, only about a third ever came home.',
        ],

        [
            'type' => 'video',
            'chapter' => 'See it for yourself',
            'location' => 'The Dutch East Indies',
            'youtube' => 'https://www.youtube.com/watch?v=ffQoNcTZmJ4',
            'controls' => true,
            'fit' => 'fit',
            'script' => 'This is the official Canon van Nederland clip about the VOC. Watch it, then keep one question in your head: who is telling this story, and whose story is missing from it?',
        ],

        [
            'type' => 'map',
            'chapter' => 'The empire on a map',
            'location' => 'The Netherlands',
            'year' => 1700,
            'qid' => 'Q55',
            'script' => 'Here is the small, wet, wind-blown country the whole thing was run from. Amsterdam, where the shares were traded and the profits landed. Texel, where the fleets gathered before sailing. Hoorn and Enkhuizen, the little harbour towns on the Zuiderzee that sent out captains far beyond anything they could have imagined. The Republic was about the size of a modern province, and for two centuries it reached halfway around the world.',
            'labels' => [
                ['Amsterdam — the VOC exchange', 4.8952, 52.3702],
                ['Texel — the fleets gathered here', 4.7970, 53.0546],
                ['Hoorn', 5.0597, 52.6425],
                ['Middelburg — the Zeeland chamber', 3.6109, 51.4988],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'The fall',
            'location' => 'Amsterdam',
            'year' => 1799,
            'image' => 'VOC aandeel 1606',
            'prefer' => 'art',
            'script' => 'The VOC did not end in glory. Through the 1700s it was eaten away by corruption, by the enormous cost of its wars, and by competition from the British. Its debts grew until they could no longer be hidden. On the first of January 1799 the company was formally dissolved, and the Dutch state took over both its territories and its debts. Some people joked that the letters VOC now stood for Vergaan Onder Corruptie — perished through corruption. The territories it left behind became the Dutch East Indies, and eventually, after a hard war of independence, the Republic of Indonesia.',
        ],

        [
            'type' => 'story',
            'chapter' => 'How should we remember it?',
            'location' => 'Amsterdam',
            'year' => 2020,
            'image' => 'Amsterdam grachtengordel herenhuis',
            'prefer' => 'photo',
            'script' => 'The VOC invented things we still use: the public company, the stock exchange, the idea of investing in something you will never see. It also killed and enslaved people on a large scale to protect a monopoly on a spice. Both of those sentences are true at the same time, and that is exactly what makes it worth studying. In the Netherlands today there are real arguments about street names, statues and museum labels. What would you write on the label?',
        ],

        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'What did you learn?',
            'questions' => [
                [
                    'q' => 'What made the VOC different from an ordinary trading company?',
                    'o' => [
                        'It could wage war, sign treaties and mint coins',
                        'It only traded within Europe',
                        'It was owned by the king',
                        'It paid no wages',
                    ],
                    'c' => 0,
                    'e' => 'The States-General gave the VOC powers that normally belong only to a state.',
                ],
                [
                    'q' => 'What was clever about the Brouwer Route?',
                    'o' => [
                        'It avoided all storms',
                        'It used the westerly winds of the Roaring Forties to cross fast',
                        'It followed the African coast closely',
                        'It went through the Suez Canal',
                    ],
                    'c' => 1,
                    'e' => 'Riding the Roaring Forties cut the voyage from over a year to about six months.',
                ],
                [
                    'q' => 'Why did so many VOC ships accidentally reach Australia?',
                    'o' => [
                        'They were exploring on purpose',
                        'Their maps were stolen',
                        'They could not measure longitude, so they turned north too late',
                        'They were blown there by hurricanes',
                    ],
                    'c' => 2,
                    'e' => 'Sailors could measure latitude but not longitude, so judging when to turn north was guesswork.',
                ],
                [
                    'q' => 'What happened on the Banda Islands in 1621?',
                    'o' => [
                        'A peaceful trade agreement was signed',
                        'The VOC almost destroyed the entire population to control nutmeg',
                        'A volcano erupted',
                        'The islands were sold to Portugal',
                    ],
                    'c' => 1,
                    'e' => 'Jan Pieterszoon Coen\'s forces killed, starved, expelled or enslaved almost the whole Bandanese population.',
                ],
                [
                    'q' => 'Roughly what share of the people who sailed east for the VOC came home again?',
                    'o' => ['Almost all of them', 'About two thirds', 'About one third', 'Fewer than one in ten'],
                    'c' => 2,
                    'e' => 'Disease, shipwreck and warfare meant only around a third of the roughly one million who sailed east returned.',
                ],
                [
                    'q' => 'Why was the VOC finally dissolved in 1799?',
                    'o' => [
                        'The spices ran out',
                        'Debt, corruption and the cost of war',
                        'The Pope forbade it',
                        'Its ships were all sunk in one storm',
                    ],
                    'c' => 1,
                    'e' => 'Corruption and enormous war debts destroyed it; the Dutch state absorbed both its territories and its debts.',
                ],
            ],
        ],
    ],
];
