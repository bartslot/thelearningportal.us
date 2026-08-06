<?php

declare(strict_types=1);

/**
 * Dutch slavery and the West India Company.
 *
 * Told with care: the focus is on the system, the scale, the resistance and the reckoning —
 * and on enslaved people as people with names and actions, never as cargo. The imagery is
 * deliberately of places, documents and memorials rather than depictions of enslaved bodies.
 */
return [
    'key' => 'Dutch slavery and the West India Company',
    'title' => 'Dutch Slavery: the trade, the resistance, the reckoning',
    'subject' => 'history',
    'language' => 'nl',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [
        [
            'type' => 'quiz', 'when' => 'pre', 'chapter' => 'What do you already know?',
            'questions' => [
                ['q' => 'Which Dutch company organised most of the Atlantic slave trade?', 'o' => ['The VOC (East India Company)', 'The WIC (West India Company)', 'The Bank of Amsterdam', 'The Dutch navy'], 'c' => 1, 'e' => 'The West-Indische Compagnie, founded in 1621, ran the Atlantic trade.'],
                ['q' => 'In which year did the Netherlands abolish slavery in its colonies?', 'o' => ['1795', '1834', '1863', '1900'], 'c' => 2, 'e' => '1863 — later than Britain and France.'],
                ['q' => 'What is Keti Koti?', 'o' => ['A Dutch harbour', 'The annual commemoration of the end of slavery', 'A type of ship', 'A museum in Rotterdam'], 'c' => 1, 'e' => 'Keti Koti means "the chains are broken", commemorated on 1 July.'],
                ['q' => 'Roughly how many enslaved Africans did Dutch ships transport across the Atlantic?', 'o' => ['About 6,000', 'About 60,000', 'About 600,000', 'About 6 million'], 'c' => 2, 'e' => 'Around 600,000 people — roughly 5% of the whole transatlantic trade.'],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'A company for the Atlantic', 'location' => 'Amsterdam', 'year' => 1621,
            'image' => 'West-Indische Compagnie WIC', 'prefer' => 'art',
            'script' => 'In 1621, nineteen years after the VOC, the Dutch Republic founded a second great company: the West-Indische Compagnie, the WIC. Its territory was the Atlantic — West Africa, Brazil, the Caribbean and North America. It began as a way to attack Spanish and Portuguese shipping, and it made a spectacular fortune in 1628 when Piet Hein captured the Spanish silver fleet. But the business that lasted, and that shaped the Atlantic world for two centuries, was the trade in human beings.',
        ],
        [
            'type' => 'story', 'chapter' => 'How the system worked', 'location' => 'The Atlantic', 'year' => 1700,
            'image' => 'driehoekshandel Atlantische slavenhandel kaart', 'prefer' => 'art',
            'script' => 'The trade ran in a triangle. Ships left Dutch ports carrying textiles, guns, alcohol and iron. On the coast of West Africa those goods were exchanged for people — men, women and children who had been captured in raids and wars, often far inland, and marched to the coast. The ships then crossed the Atlantic, and the survivors were sold in the Caribbean and South America to work on plantations. The ships returned to Europe loaded with sugar, coffee, cacao and tobacco that those same people had produced. Every leg of that triangle made money.',
        ],
        [
            'type' => '3d', 'chapter' => 'Elmina Castle', 'location' => 'Elmina, Ghana', 'year' => 1637,
            'sketchfab' => 'https://sketchfab.com/3d-models/elmina-castle-ghana-338068b6c91945518c7322b243bc496b',
            'script' => 'This is Elmina Castle on the coast of Ghana, scanned by heritage researchers. The Dutch took it from the Portuguese in 1637 and held it for over two hundred years. Turn it around and look at it honestly: the upper floors held offices, a chapel and the governor\'s rooms, with a sea breeze and a view. Below them are the dungeons, where hundreds of people at a time were held in the dark before being taken through a doorway to the boats. It is a UNESCO World Heritage site now, and people from across the world come to stand in those rooms.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'The crossing', 'location' => 'The Middle Passage', 'year' => 1750,
            'title' => 'The Middle Passage', 'date_label' => '17th – 18th century',
            'story' => 'The Atlantic crossing took roughly two to three months. People were held below deck in spaces too low to stand up in, chained, in heat and filth, brought up only briefly for air. Disease spread quickly. On Dutch ships, historians estimate that roughly one in every eight people aboard died before reaching the Americas — and more died in the weeks after landing. Around 600,000 Africans were carried on Dutch ships in total. It is important to say what that number means: 600,000 individual people, each with a name, a family and a home, most of whose names were never written down.',
            'images' => ['Elmina Castle Ghana', 'slavernij monument Amsterdam Oosterpark', 'suikerplantage Suriname 18e eeuw'],
            'prefer' => 'photo',
            'script' => 'The Atlantic crossing took two to three months. People were held below deck in spaces too low to stand up in, chained, in heat and filth. On Dutch ships, historians estimate roughly one in every eight people aboard died before reaching the Americas, and more died in the weeks after landing. Around six hundred thousand Africans were carried on Dutch ships. It matters to say what that number means: six hundred thousand individual people, each with a name and a family, most of whose names were never written down.',
        ],
        [
            'type' => 'story', 'chapter' => 'Suriname and the sugar', 'location' => 'Suriname', 'year' => 1770,
            'image' => 'plantage Suriname 18e eeuw', 'prefer' => 'art',
            'script' => 'The Dutch colony of Suriname, on the north coast of South America, became one of the harshest plantation societies in the Americas. Sugar, coffee and cacao were grown there on hundreds of plantations, many of them owned by investors sitting comfortably in Amsterdam who never saw the place. The work was brutal and the punishments were worse; the death rate was so high that the plantations needed a constant supply of newly enslaved people simply to keep running. The profits flowed back into Dutch canal houses, banks and country estates.',
        ],
        [
            'type' => 'story', 'chapter' => 'Resistance: the Marrons', 'location' => 'Suriname', 'year' => 1760,
            'image' => 'Marrons Suriname', 'prefer' => 'photo',
            'script' => 'People did not simply accept this. Enslaved men and women slowed work, broke tools, ran away, and rose in revolt. In Suriname, thousands escaped into the rainforest and built free communities there — the Marrons. They farmed, organised, fought off military expeditions sent to destroy them, and in 1760 the Ndyuka forced the Dutch colonial government to sign a peace treaty recognising their freedom and their land. Their descendants live in Suriname and the Netherlands today, and their languages and drumming traditions survive. Resistance is as much a part of this history as the trade is.',
        ],
        [
            'type' => 'map', 'chapter' => 'The Dutch Atlantic', 'location' => 'The Netherlands', 'year' => 1750, 'qid' => 'Q55',
            'script' => 'The trade was run from a handful of ordinary Dutch towns. Middelburg in Zeeland was the headquarters of the Middelburgsche Commercie Compagnie, whose ledgers survive in astonishing detail and are now a key historical source. Amsterdam and Rotterdam financed and insured the voyages. Flushing fitted out the ships. None of these towns look like the site of a global crime, and that is precisely the point: this was ordinary business, conducted by respectable people, recorded in neat handwriting.',
            'labels' => [
                ['Middelburg — the MCC', 3.6109, 51.4988],
                ['Amsterdam — finance and insurance', 4.8952, 52.3702],
                ['Rotterdam', 4.4777, 51.9244],
            ],
        ],
        [
            'type' => 'video', 'chapter' => 'The story in two minutes', 'location' => 'The Netherlands',
            'youtube' => 'https://www.youtube.com/watch?v=moB7aet7XlQ', 'controls' => true, 'fit' => 'fit',
            'script' => 'This is the official Canon van Nederland clip about slavery. Watch it carefully, and notice whose voices the story is told through.',
        ],
        [
            'type' => 'story', 'chapter' => '1863, and then ten more years', 'location' => 'Suriname', 'year' => 1863,
            'image' => 'afschaffing slavernij 1863 Nederland', 'prefer' => 'art',
            'script' => 'The Netherlands abolished slavery in its colonies on the first of July 1863 — later than Britain, later than France. Even then, freedom came with conditions: in Suriname, formerly enslaved people were required to keep working on the plantations for a further ten years of so-called state supervision, so real freedom did not arrive until 1873. And it was the slave owners, not the people they had enslaved, who received compensation from the Dutch state — three hundred guilders per person.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'Keti Koti', 'location' => 'Amsterdam', 'year' => 2023,
            'title' => 'The chains are broken', 'date_label' => '1 July, every year',
            'story' => 'Every first of July, Keti Koti — "the chains are broken" in Sranantongo — is commemorated in the Netherlands and in Suriname, with a memorial ceremony at the National Slavery Monument in the Oosterpark in Amsterdam. For a long time this history was barely taught in Dutch schools. That has changed fast. In 2022 the city of Amsterdam apologised, and in December 2022 the Dutch government formally apologised for the Dutch state\'s role in slavery; in 2023 King Willem-Alexander did the same and asked for forgiveness. There is now an active national argument about what should follow an apology.',
            'images' => ['Nationaal Slavernijmonument Oosterpark', 'Keti Koti herdenking', 'Rijksmuseum slavernij tentoonstelling'],
            'prefer' => 'photo',
            'script' => 'Every first of July, Keti Koti — the chains are broken — is commemorated in the Netherlands and Suriname, at the National Slavery Monument in the Oosterpark in Amsterdam. For a long time this history was barely taught in Dutch schools. That has changed fast. In 2022 the Dutch government formally apologised for the state\'s role in slavery, and in 2023 the King did the same and asked for forgiveness. There is now an active national argument about what should follow an apology.',
        ],
        [
            'type' => 'story', 'chapter' => 'Why this is in the Canon', 'location' => 'The Netherlands', 'year' => 2025,
            'image' => 'Nationaal Slavernijmonument Amsterdam', 'prefer' => 'photo',
            'script' => 'Slavery is not a side note to Dutch history; it is part of the main story, tied directly to the wealth, the shipping, the banking and the fine buildings that the Netherlands is proud of. Hundreds of thousands of people in the Netherlands today are descended from those who were enslaved, and street names, statues and family fortunes still carry the traces. Studying it is not about blaming people alive now for things done centuries ago. It is about being able to describe your own country accurately — including the parts that are hard to look at.',
        ],
        [
            'type' => 'quiz', 'when' => 'post', 'chapter' => 'What did you learn?',
            'questions' => [
                ['q' => 'What was the WIC?', 'o' => ['The Dutch West India Company, which ran the Atlantic trade', 'A bank in Rotterdam', 'A Dutch navy fleet', 'A sugar refinery'], 'c' => 0, 'e' => 'Founded 1621 to operate in West Africa, the Caribbean and the Americas.'],
                ['q' => 'What was traded on each leg of the triangular trade?', 'o' => ['Goods to Africa, people to the Americas, plantation crops to Europe', 'Only sugar', 'Spices in all three directions', 'Only silver'], 'c' => 0, 'e' => 'Every leg of the triangle generated a profit.'],
                ['q' => 'What was Elmina Castle used for by the Dutch?', 'o' => ['A royal palace', 'A trading fort holding enslaved people before shipment', 'A monastery', 'A lighthouse'], 'c' => 1, 'e' => 'The Dutch held it from 1637; its dungeons held people awaiting the Atlantic crossing.'],
                ['q' => 'Who were the Marrons of Suriname?', 'o' => ['Plantation owners', 'Escaped enslaved people who built free communities in the rainforest', 'Dutch soldiers', 'Sugar traders'], 'c' => 1, 'e' => 'In 1760 the Ndyuka forced the colonial government into a treaty recognising their freedom.'],
                ['q' => 'What happened after abolition in 1863 in Suriname?', 'o' => ['Immediate full freedom', 'Ten more years of compulsory "state supervision" work', 'Everyone was given land', 'The colony closed'], 'c' => 1, 'e' => 'Real freedom only came in 1873 — and the owners, not the enslaved, were compensated.'],
                ['q' => 'What does Keti Koti mean?', 'o' => ['New harvest', 'The chains are broken', 'Free the sea', 'Long journey'], 'c' => 1, 'e' => 'It is Sranantongo, commemorated every 1 July.'],
            ],
        ],
    ],
];
