<?php

declare(strict_types=1);

/**
 * Abel Tasman's two voyages — the English edition. Roughly ages 13–16.
 *
 * The Dutch lesson (`lessons:make-tasman-voyage`, WMDIF8) is a bare route map: six voyage scenes,
 * no narration, nothing between the legs. This is the same story told properly, in English, and
 * it is a SEPARATE lesson rather than a translation because the schema has one script per scene —
 * there is no per-language field. config/demo.php routes English-speaking countries here and the
 * Dutch-speaking ones to WMDIF8, which is what "English users see the English version" means in
 * practice.
 *
 * Two things the Dutch version does not have:
 *
 *  - Story and gallery scenes BETWEEN the voyage legs, so the map is punctuated by the things
 *    that were actually seen — ships, coastlines, the Golden Bay encounter — instead of running
 *    leg to leg with nothing in between.
 *  - The second expedition of 1644 (voyage `tasman-1644`), which is the half of the story that
 *    matters most to Australia and which the Dutch lesson leaves out entirely.
 *
 * On Golden Bay: four Dutch sailors were killed and an unknown number of Māori were fired on. The
 * scripts below give both sides and avoid the old "Murderers' Bay" framing as though only one
 * party had been wronged — Ngāti Tūmatakōkiri were defending a coast they had every right to.
 */
return [
    'key' => 'Abel Tasman (English)',
    'title' => 'Abel Tasman: two voyages to the unknown south',
    'subject' => 'history',
    'language' => 'en',
    'grade_level' => '9',
    'tone' => 'storytelling',
    'map_style' => 'satellite',

    'scenes' => [

        [
            'type' => 'quiz',
            'when' => 'pre',
            'chapter' => 'Before we start',
            'questions' => [
                [
                    'q' => 'Who did Abel Tasman work for?',
                    'o' => ['The Dutch East India Company (VOC)', 'The Royal Navy', 'The King of Spain', 'Nobody — he paid for it himself'],
                    'c' => 0,
                    'e' => 'The VOC: a trading company with its own ships, soldiers and territory.',
                ],
                [
                    'q' => 'Which of these did Tasman reach in 1642?',
                    'o' => ['Hawaii', 'Tasmania and New Zealand', 'Japan', 'The Cape of Good Hope'],
                    'c' => 1,
                    'e' => 'He was the first European known to reach both.',
                ],
                [
                    'q' => 'What were European sailors hoping to find in the far south?',
                    'o' => ['A huge unknown southern continent', 'A route to the moon', 'The North Pole', 'Nothing in particular'],
                    'c' => 0,
                    'e' => 'Terra Australis Incognita — believed to balance the landmasses of the north.',
                ],
                [
                    'q' => 'What is a chart, to a sailor?',
                    'o' => ['A map of the sea and its coasts', 'A list of the crew', 'A ship\'s flag', 'A type of sail'],
                    'c' => 0,
                    'e' => 'And in 1642 an accurate chart was worth more than gold to a trading company.',
                ],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'A company with a navy',
            'location' => 'Batavia',
            'year' => 1642,
            'image' => 'VOC schip Oost-Indiëvaarder 17e eeuw schilderij',
            'prefer' => 'painting',
            'script' => 'Abel Tasman did not work for a king. He worked for a company. The Dutch East India Company, the VOC, was a business with shareholders — and also with warships, soldiers, forts and the legal right to make war and sign treaties. From its headquarters at Batavia, on Java, it ran the most profitable trading operation on earth. In 1642 the governor general, Anthony van Diemen, gave Tasman two ships and a question that was really about money: is there a great southern continent down there, and if there is, does it have anything worth selling?',
        ],

        [
            'type' => 'story',
            'chapter' => 'The land nobody had seen',
            'location' => 'The Southern Ocean',
            'year' => 1642,
            'image' => 'Terra Australis Incognita oude wereldkaart 17e eeuw',
            'prefer' => 'painting',
            'script' => 'For centuries European mapmakers had drawn an enormous continent across the bottom of the world. They called it Terra Australis Incognita, the unknown southern land. There was no evidence for it whatsoever. The reasoning was that the world needed balancing: so much land in the north, therefore so much land in the south, or the globe would somehow topple. It is worth remembering that this is what Tasman was sent to find. He was looking for something that had been imagined first and searched for afterwards.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1642',
            'leg' => 0,
            'intro' => true,
            'chapter' => 'Out of Batavia',
            'location' => 'Mauritius',
            'year' => 1642,
            'title' => 'Two ships, one year of supplies',
            'date_label' => 'August – September 1642',
            'story' => 'The Heemskerck, a small warship, and the Zeehaen, a cargo flute. Around a hundred and ten men. They sail first to Mauritius to refit and take on water and wood, because everything after that is guesswork.',
            'images' => ['VOC vloot schepen zeilend schilderij', 'Mauritius VOC 17e eeuw'],
            'prefer' => 'painting',
            'script' => 'Follow the line. On the fourteenth of August 1642, two ships leave Batavia: the Heemskerck, a small warship, and the Zeehaen, a cargo flute, with about a hundred and ten men between them. Their first stop is Mauritius, where they spend a month repairing, taking on water, wood and food. It is the last friendly harbour they will see. Everything after Mauritius is guesswork.',
        ],

        [
            'type' => 'story',
            'chapter' => 'Sailing by the roaring forties',
            'location' => 'The Southern Ocean',
            'year' => 1642,
            'image' => 'zeilschip storm hoge zee 17e eeuw schilderij',
            'prefer' => 'painting',
            'script' => 'From Mauritius they turn south, down into the belt of westerly winds that sailors would later call the roaring forties. It is cold, grey and violent, and it is also fast: the wind blows steadily from behind and pushes them east across an empty ocean for weeks. They know their latitude from the sun. They can only guess their longitude, because no clock at sea in 1642 keeps time well enough to measure it. So they know how far south they are, and only roughly how far east.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1642',
            'leg' => 1,
            'chapter' => 'Van Diemen\'s Land',
            'location' => 'Tasmania',
            'year' => 1642,
            'title' => 'A coast where no coast should be',
            'date_label' => 'November 1642',
            'story' => 'On the twenty fourth of November they sight land. Tasman names it Van Diemen\'s Land after his employer. A carpenter swims ashore through the surf to plant a flag and claim it. They see smoke, and notched trees with steps cut far apart, and never meet anybody.',
            'images' => ['Tasmanië kust 17e eeuw gravure', 'Abel Tasman Van Diemensland kaart'],
            'prefer' => 'painting',
            'script' => 'On the twenty fourth of November they sight land, and it is where no land was supposed to be. Tasman names it Van Diemen\'s Land, after the man who sent him. A ship\'s carpenter swims ashore through the surf to plant a flag, because the sea is too rough to land a boat. They find smoke rising inland, and trees with steps notched into them so far apart that the crew decide the people here must be giants. They are not. The Palawa had simply been climbing those trees for thousands of years. The two groups never meet.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1642',
            'leg' => 2,
            'chapter' => 'New Zealand',
            'location' => 'Golden Bay',
            'year' => 1642,
            'title' => 'The first meeting',
            'date_label' => 'December 1642',
            'story' => 'Nine days east, another coast. Waka come out to meet the ships. Neither side can understand a word the other says, and both are reading the situation through their own rules.',
            'images' => ['Abel Tasman Nieuw-Zeeland waka 1642 gravure', 'Maori waka kano 17e eeuw tekening'],
            'prefer' => 'painting',
            'script' => 'Nine days further east they find another coast, and this one has people. Canoes come out to look at the ships. Trumpets are blown from both sides — the Dutch meaning it as a greeting, Ngāti Tūmatakōkiri, as far as we can tell, answering a challenge. Neither side speaks a word the other understands. Each is reading the other through its own rules, and both of them are wrong.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'What happened at Golden Bay',
            'location' => 'Golden Bay',
            'year' => 1642,
            'title' => 'Four men killed, and a name that lasted',
            'date_label' => '19 December 1642',
            'story' => 'A boat rowing between the two Dutch ships was rammed. Four sailors were killed. The Dutch fired on the canoes as they pulled away, and hit an unknown number of people. Tasman never landed. He wrote the place down as Moordenaarsbaai — Murderers\' Bay — and sailed on, and the name stuck for three hundred years. Read it from the other side: unknown ships had anchored off a coast that belonged to somebody, without asking, and were approached exactly as intruders would be. Today the bay is called Golden Bay, or Mohua.',
            'images' => [
                'Moordenaarsbaai 1642 Gilsemans tekening',
                'Abel Tasman Nieuw-Zeeland ontmoeting 1642',
                'Maori waka oorlogskano tekening',
            ],
            'prefer' => 'painting',
            'script' => 'A small boat rowing between the two Dutch ships was rammed by a canoe. Four sailors were killed. The Dutch fired on the canoes as they pulled away and hit an unknown number of people. Tasman never set foot on shore. He wrote the place into his journal as Moordenaarsbaai, Murderers\' Bay, and sailed away, and that name stayed on European maps for three hundred years. Now read it from the other side. Ships nobody recognised had anchored off a coast that belonged to somebody, without asking, and were met the way intruders would be met. Today the bay is called Golden Bay, or Mohua.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1642',
            'leg' => 3,
            'chapter' => 'North to Tonga and Fiji',
            'location' => 'Tonga',
            'year' => 1643,
            'title' => 'A better welcome',
            'date_label' => 'January 1643',
            'story' => 'Sailing north they reach Tonga, where the reception could hardly be more different: food, water and trade, freely offered. Then Fiji, and its reefs, which nearly end the voyage.',
            'images' => ['Tonga eilanden 17e eeuw gravure', 'Stille Oceaan eilanden zeilschip gravure'],
            'prefer' => 'painting',
            'script' => 'They sail north and reach Tonga, where the welcome could hardly be more different: people come out with food and water and trade willingly, and the Dutch stay several days. Then they pass through the reefs of Fiji in poor visibility, and very nearly lose both ships. Tasman charts what he can. He still believes the coast he found in the south is the western edge of one great continent, and he has no idea that New Zealand is two long islands.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1642',
            'leg' => 4,
            'chapter' => 'Home with a disappointment',
            'location' => 'Batavia',
            'year' => 1643,
            'title' => 'Ten months, no cargo',
            'date_label' => 'June 1643',
            'story' => 'They reach Batavia in June 1643 after ten months at sea, having sailed right around Australia without ever seeing it. The VOC is not impressed: no trade, no spices, no gold.',
            'images' => ['Batavia haven VOC 17e eeuw schilderij', 'VOC kaart Oost-Indië 17e eeuw'],
            'prefer' => 'painting',
            'script' => 'They reach Batavia in June 1643, ten months after leaving. Look at what the track has actually done: they have sailed right around Australia without once seeing it. The Council of the Indies is not impressed. No trade was opened, no spices found, no gold, no useful new route. In the language of the company, the voyage was a failure — and that judgement is exactly why a second expedition was sent, with a much narrower question.',
        ],

        [
            'type' => 'story',
            'chapter' => 'The question changes',
            'location' => 'Batavia',
            'year' => 1644,
            'image' => 'VOC Heeren XVII vergadering 17e eeuw prent',
            'prefer' => 'painting',
            'script' => 'For the second voyage the company stops asking about a mythical continent and starts asking about something practical. Dutch ships had been bumping into the western coast of what they called the Southland for forty years, usually by accident and often by wreck. Is it one landmass or several? Does it join New Guinea, or is there a passage between them? And is there a strait a trading fleet could use? In 1644 Tasman is given three ships and told to follow the coast and write down exactly what is there.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1644',
            'leg' => 0,
            'intro' => true,
            'chapter' => 'The second expedition',
            'location' => 'Batavia',
            'year' => 1644,
            'title' => 'Limmen, Zeemeeuw and Bracq',
            'date_label' => 'February 1644',
            'story' => 'Three ships this time, and a survey rather than an exploration: hug the coast, sound the depths, draw what you see.',
            'images' => ['VOC jacht schip 17e eeuw schilderij', 'VOC scheepswerf schepen prent'],
            'prefer' => 'painting',
            'script' => 'In February 1644 Tasman leaves Batavia again, this time with three ships: the Limmen, the Zeemeeuw and the Bracq. The orders are different in spirit. This is not exploration, it is a survey. Stay close to the coast. Sound the depths. Draw what you actually see. And find out, once and for all, whether New Guinea and the Southland are joined.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1644',
            'leg' => 2,
            'chapter' => 'The strait he sailed past',
            'location' => 'Torres Strait',
            'year' => 1644,
            'title' => 'The most consequential mistake of the voyage',
            'date_label' => 'April 1644',
            'story' => 'Between New Guinea and Cape York there is a strait, shallow and choked with reefs and islands. Tasman looks into it, decides it is a bay, and turns south. Europe would go on believing the two were joined for another century and a quarter.',
            'images' => ['Torres Straat kaart 17e eeuw', 'Nieuw-Guinea kust gravure 17e eeuw'],
            'prefer' => 'painting',
            'script' => 'Here is the moment the whole voyage turns on. Between New Guinea and Cape York there is a strait — shallow, scattered with reefs and islands, and genuinely difficult to read from a ship\'s deck. Tasman looks into it and concludes it is a bay. He turns south. Because of that one judgement, European maps went on joining New Guinea to Australia for another hundred and twenty five years, until James Cook sailed through the same water in 1770. Torres had in fact passed through it in 1606, but his report sat unread in the Spanish archives.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1644',
            'leg' => 3,
            'chapter' => 'The Gulf and Arnhem Land',
            'location' => 'Gulf of Carpentaria',
            'year' => 1644,
            'title' => 'Charting an empty-looking coast',
            'date_label' => 'May – June 1644',
            'story' => 'Down into the Gulf of Carpentaria and west along Arnhem Land, sounding as they go. The coast is low, hot and dry, and to men looking for a port and a market it appears to offer nothing.',
            'images' => ['Arnhem Land kust Australië gravure', 'Golf van Carpentaria oude kaart'],
            'prefer' => 'painting',
            'script' => 'They work their way down into the Gulf of Carpentaria and then west along Arnhem Land, sounding the depths as they go and putting the shape of the coast on paper for the first time in Europe. It is low, hot and dry. To men looking for a harbour, a market and a cargo, it seems to offer nothing at all. They are sailing past countries that had been lived in, named and cared for by Aboriginal nations for more than sixty thousand years — the Yolŋu among them — and the journals barely record it.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'tasman-1644',
            'leg' => 5,
            'chapter' => 'New Holland',
            'location' => 'Batavia',
            'year' => 1644,
            'title' => 'Four thousand seven hundred kilometres of coastline',
            'date_label' => 'August 1644',
            'story' => 'Tasman returns in August 1644 having charted something like four thousand seven hundred kilometres of the northern and western coast. On the new charts the land has a name: Nieuw Holland, New Holland.',
            'images' => ['Nieuw Holland kaart 17e eeuw Australië', 'Tasman kaart 1644 Australië'],
            'prefer' => 'painting',
            'script' => 'He is back in Batavia by the fourth of August 1644, having charted something like four thousand seven hundred kilometres of the northern and western coast. On the charts that come out of this voyage, the land finally has a European name: Nieuw Holland, New Holland. And once again the company is disappointed — no gold, no spices, no passage, no port. They stop looking. The Dutch had found Australia and decided it was not worth having.',
        ],

        [
            'type' => 'story',
            'chapter' => 'The charts nobody was allowed to see',
            'location' => 'Amsterdam',
            'year' => 1650,
            'image' => 'Blaeu wereldkaart 17e eeuw Nieuw Holland',
            'prefer' => 'painting',
            'script' => 'There is one more reason Tasman is less famous than Cook, and it is not about sailing. The VOC treated charts as trade secrets. Knowing where a coast is, and where the reefs and the fresh water are, was a commercial advantage, and the company kept it. Tasman\'s journals and charts were not properly published for well over a century. So the knowledge existed, in a cabinet in Amsterdam, while the maps other Europeans used stayed blank. It is a useful thing to notice about history: what gets known depends on who is allowed to read it.',
        ],

        [
            'type' => 'story',
            'chapter' => 'What his voyages left behind',
            'location' => 'Australia and New Zealand',
            'year' => 2025,
            'image' => 'Tasmanië Nieuw-Zeeland moderne kaart',
            'prefer' => 'photo',
            'script' => 'Tasmania carries his name. So does the Tasman Sea, between Australia and New Zealand, and so do a glacier, a bridge and a great many streets. New Zealand keeps the name a Dutch mapmaker gave it after Zeeland, the Dutch province. But the people Tasman met were not discovered by anybody — Māori had been in Aotearoa for around four hundred years before he arrived, and Aboriginal Australians for more than sixty thousand. What Tasman actually did was join two halves of the world\'s knowledge of itself, at a cost, and then hand his charts to a company that locked them in a drawer.',
        ],

        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'What did you learn?',
            'questions' => [
                [
                    'q' => 'Why did the VOC consider the 1642 voyage a failure?',
                    'o' => [
                        'It opened no trade and found nothing to sell',
                        'Both ships were lost',
                        'Tasman never found any land',
                        'The crew mutinied',
                    ],
                    'c' => 0,
                    'e' => 'He reached Tasmania, New Zealand, Tonga and Fiji — but brought back no cargo.',
                ],
                [
                    'q' => 'What did Tasman miss in 1644, and why did it matter?',
                    'o' => [
                        'Torres Strait — so Europe kept joining New Guinea to Australia for 125 years',
                        'The Cape of Good Hope',
                        'The mouth of the Amazon',
                        'The Suez Canal',
                    ],
                    'c' => 0,
                    'e' => 'He took it for a bay. Cook sailed through it in 1770.',
                ],
                [
                    'q' => 'Why is Tasman less famous than Cook, despite arriving 128 years earlier?',
                    'o' => [
                        'The VOC kept his charts secret as trade information',
                        'He never wrote anything down',
                        'His voyages were shorter',
                        'He was not Dutch',
                    ],
                    'c' => 0,
                    'e' => 'Knowledge of a coast was commercially valuable, so the company did not publish it.',
                ],
                [
                    'q' => 'How long had people been living in Aotearoa before Tasman arrived?',
                    'o' => ['Around 400 years', 'Nobody lived there', 'About 20 years', 'Around 10 years'],
                    'c' => 0,
                    'e' => 'And Aboriginal Australians had been on their country for more than 60,000 years.',
                ],
            ],
        ],
    ],
];
