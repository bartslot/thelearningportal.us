<?php

declare(strict_types=1);

/** Rembrandt and the Dutch Golden Age — light, money, and who paid for it. */
return [
    'key' => 'Rembrandt and the Dutch Golden Age',
    'title' => 'Rembrandt and the Dutch Golden Age',
    'subject' => 'history',
    'language' => 'en',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [
        [
            'type' => 'quiz', 'when' => 'pre', 'chapter' => 'What do you already know?',
            'questions' => [
                ['q' => 'Which century is called the Dutch Golden Age?', 'o' => ['The 15th', 'The 17th', 'The 19th', 'The 20th'], 'c' => 1, 'e' => 'The 1600s — when the young Dutch Republic became one of the richest places on earth.'],
                ['q' => 'What is Rembrandt\'s most famous painting called?', 'o' => ['The Night Watch', 'The Milkmaid', 'The Starry Night', 'Girl with a Pearl Earring'], 'c' => 0, 'e' => 'De Nachtwacht — painted in 1642 and now in the Rijksmuseum.'],
                ['q' => 'Roughly how many self-portraits did Rembrandt make?', 'o' => ['About 3', 'About 15', 'About 80', 'About 500'], 'c' => 2, 'e' => 'Around eighty, across his whole life — a face ageing in public.'],
                ['q' => 'What made the Dutch Republic so wealthy in the 1600s?', 'o' => ['Gold mines', 'World trade and shipping', 'Winning a war against France', 'Selling oil'], 'c' => 1, 'e' => 'Trade, shipping and finance — with the VOC at the centre of it.'],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'A miller\'s son from Leiden', 'location' => 'Leiden', 'year' => 1606,
            'image' => 'commons:Rembrandt van Rijn - Self-Portrait - Google Art Project.jpg', 'prefer' => 'art',
            'script' => 'Rembrandt Harmenszoon van Rijn was born in Leiden in 1606, the son of a miller. He was clever enough for university and enrolled at fourteen, then walked out almost immediately to become a painter instead. It was an odd choice for a boy of his background. Within ten years he would be the most sought-after portrait painter in Amsterdam.',
        ],
        [
            'type' => 'story', 'chapter' => 'The richest city in Europe', 'location' => 'Amsterdam', 'year' => 1640,
            'image' => 'Amsterdam Dam Stadhuis 17e eeuw schilderij', 'prefer' => 'art',
            'script' => 'Amsterdam in the 1600s was a boom town. Ships from every ocean unloaded at its quays. Its bank was the most trusted in Europe, its stock exchange the busiest, and its population had quadrupled in fifty years. New canals were dug in a great half circle and lined with tall narrow houses, because you paid tax on the width of your frontage. Into that noisy, wealthy, ambitious city came a young painter looking for customers.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'Painting for a middle class', 'location' => 'Amsterdam', 'year' => 1650,
            'title' => 'A country without a court', 'date_label' => '17th century',
            'story' => 'Everywhere else in Europe, painters worked for kings, popes and princes. The Dutch Republic had none of those. It had merchants, brewers, surgeons, shopkeepers and militia officers — and they all wanted paintings for their own front rooms. So Dutch artists painted what those people wanted to look at: landscapes, sea views, church interiors, winter scenes, still lifes of food, and above all portraits. Historians estimate that millions of paintings were produced in the Republic in a single century. Ordinary houses had art on the walls, which astonished foreign visitors.',
            'images' => ['Hollands landschap 17e eeuw schilderij', 'stilleven 17e eeuw Nederlandse schilderkunst', 'winterlandschap schaatsers Hollandse meesters'],
            'prefer' => 'art',
            'script' => 'Everywhere else in Europe, painters worked for kings, popes and princes. The Dutch Republic had none of those. It had merchants, brewers, surgeons and shopkeepers, and they all wanted paintings for their own front rooms. So Dutch artists painted what those people wanted to look at: landscapes, sea views, winter scenes, still lifes, and above all portraits. Millions of paintings were made in a single century. Ordinary houses had art on the walls, which astonished foreign visitors.',
        ],
        [
            'type' => 'story', 'chapter' => 'The trick with light', 'location' => 'Amsterdam', 'year' => 1632,
            'image' => 'Rembrandt Anatomische les Dr Nicolaes Tulp', 'prefer' => 'art',
            'script' => 'Rembrandt\'s secret was light. He would push most of a picture down into deep brown shadow, then let a single source of light fall exactly where he wanted your eye to go — a face, a hand, a page. He learned it partly from Italian painting and partly from simply watching. In 1632 his group portrait of a surgeon giving an anatomy lesson made him famous overnight, because instead of a stiff row of men he showed a moment of concentration, everyone leaning in to look.',
        ],
        [
            'type' => 'story', 'chapter' => 'The Night Watch', 'location' => 'Amsterdam', 'year' => 1642,
            'image' => 'commons:The Night Watch - HD.jpg', 'prefer' => 'art',
            'script' => 'In 1642 Rembrandt delivered the painting we now call The Night Watch. A militia company had each paid to be in it, and they expected a tidy row of portraits. What they got was a company bursting into motion: a drum beating, a dog barking, a flag going up, a musket firing, and a strange small girl glowing in the middle of it. It is nearly four metres wide. It is not actually a night scene at all — centuries of dark varnish gave it that name.',
        ],
        [
            'type' => '3d', 'chapter' => 'The house he was born in', 'location' => 'Leiden', 'year' => 1606,
            'sketchfab' => 'https://sketchfab.com/3d-models/rembrandts-birthplace-leiden-free-download-26e18c8a4e39499881609d50d8002ae7',
            'script' => 'This is the house in Leiden where Rembrandt was born in 1606 — a working mill family\'s home, with its stepped Dutch gable and small leaded windows. Turn it around. Nothing about it suggests that the boy inside would end up with a room of his own in the national museum. He walked out of this street, out of university, and into a painter\'s workshop.'
        ],
        [
            'type' => 'map', 'chapter' => 'The world he lived in', 'location' => 'The Netherlands', 'year' => 1650, 'qid' => 'Q55',
            'script' => 'Rembrandt\'s whole life fits into a handful of towns. Leiden, where he was born and first painted. Amsterdam, where he made his name, his fortune, and then lost both. Delft, where Vermeer worked at the same time. Haarlem, home of Frans Hals. This tiny, waterlogged corner of Europe produced one of the greatest bursts of painting in history.',
            'labels' => [
                ['Leiden — born 1606', 4.4970, 52.1601],
                ['Amsterdam — the studio', 4.8952, 52.3702],
                ['Delft — Vermeer', 4.3571, 52.0116],
                ['Haarlem — Frans Hals', 4.6462, 52.3874],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'Everything falls apart', 'location' => 'Amsterdam', 'year' => 1656,
            'image' => 'Rembrandt Saskia portret', 'prefer' => 'art',
            'script' => 'Success did not last. His wife Saskia died young, in 1642, the same year as The Night Watch. Three of their four children had already died as babies. Rembrandt spent money he did not have on a grand house and on his art collection, and in 1656 he was declared bankrupt. His possessions were sold at auction, item by item. He kept painting. The strange thing is that the late work, made when he had lost nearly everything, is the work most people now consider his greatest.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'A face across a lifetime', 'location' => 'Amsterdam', 'year' => 1660,
            'title' => 'Eighty self-portraits', 'date_label' => '1628 – 1669',
            'story' => 'No painter before Rembrandt had ever looked at himself so often, or so honestly. He painted himself as a cocky young man in a feathered cap, as a prosperous burgher, as a laughing old man, and finally as someone worn down and clear-eyed, with a face like weather. He hid nothing: the swollen nose, the tired skin, the thinning hair. Put the self-portraits in a row and you are watching a human being age in front of you, which is something no other artist of his time thought to do.',
            'images' => ['commons:Self portrait as a painter - Vincent van Gogh.jpg', 'Rembrandt zelfportret oude man', 'Rembrandt zelfportret 1640'],
            'prefer' => 'art',
            'script' => 'No painter before Rembrandt had ever looked at himself so often, or so honestly. He painted himself as a cocky young man in a feathered cap, as a prosperous citizen, and finally as someone worn down and clear eyed, with a face like weather. He hid nothing: the swollen nose, the tired skin. Put the self portraits in a row and you are watching a human being age in front of you.',
        ],
        [
            'type' => 'video', 'chapter' => 'Look closer', 'location' => 'The Rijksmuseum',
            'youtube' => 'https://www.youtube.com/watch?v=CnuCs69Q400', 'controls' => true, 'fit' => 'fit',
            'script' => 'This is the official Canon van Nederland clip about Rembrandt. Watch how a painting can be read as evidence — not just as something beautiful, but as a document of the world that made it.',
        ],
        [
            'type' => 'story', 'chapter' => 'The other side of the gold', 'location' => 'Amsterdam', 'year' => 1660,
            'image' => 'Amsterdam grachtengordel 17e eeuw', 'prefer' => 'art',
            'script' => 'We call it a Golden Age, and for the people who commissioned these paintings it truly was. But the money came from a trading empire, and that empire ran on conquest and on slavery. The same city that produced Rembrandt also ran slave forts on the coast of West Africa. Historians and museums in the Netherlands now argue about whether the phrase Golden Age should be used at all, because it was golden only if you were standing in a particular place. The Rijksmuseum has largely stopped using it. What do you think?',
        ],
        [
            'type' => 'story', 'chapter' => 'Why he still matters', 'location' => 'Amsterdam', 'year' => 1669,
            'image' => 'Rijksmuseum Amsterdam gebouw', 'prefer' => 'photo',
            'script' => 'Rembrandt died in 1669, poor, and was buried in an unmarked grave. Today The Night Watch has a room of its own in the Rijksmuseum and millions of people a year stand in front of it. What they are looking at is not just skill. It is attention: a painter who looked harder at ordinary faces than anyone had before, and who kept looking even when his own life fell apart.',
        ],
        [
            'type' => 'quiz', 'when' => 'post', 'chapter' => 'What did you learn?',
            'questions' => [
                ['q' => 'Why did Dutch painters mostly work for merchants rather than kings?', 'o' => ['Kings had banned painting', 'The Republic had no royal court, but a large wealthy middle class', 'Painting was illegal at court', 'Merchants paid less'], 'c' => 1, 'e' => 'With no court to serve, artists sold to citizens — so they painted the subjects citizens wanted.'],
                ['q' => 'What was so unusual about The Night Watch?', 'o' => ['It was painted at night', 'It showed a militia company bursting into movement rather than posing in a row', 'It was tiny', 'It had no people in it'], 'c' => 1, 'e' => 'Rembrandt turned a formal group portrait into a moment of action.'],
                ['q' => 'What was Rembrandt\'s signature technique?', 'o' => ['Painting only in bright colours', 'Dramatic light against deep shadow', 'Painting on glass', 'Using no brushes'], 'c' => 1, 'e' => 'He pushed most of a picture into shadow and lit exactly what mattered.'],
                ['q' => 'What happened to Rembrandt in 1656?', 'o' => ['He was knighted', 'He was declared bankrupt and his possessions auctioned', 'He moved to Italy', 'He stopped painting'], 'c' => 1, 'e' => 'He went bankrupt — yet his greatest work came afterwards.'],
                ['q' => 'Why do some Dutch museums now avoid the phrase "Golden Age"?', 'o' => ['It is hard to translate', 'Because the wealth rested on colonial conquest and slavery', 'Because gold was never traded', 'Because it was not really a century'], 'c' => 1, 'e' => 'It was only golden from one point of view; the Rijksmuseum has largely dropped the term.'],
                ['q' => 'Roughly how many self-portraits did Rembrandt leave behind?', 'o' => ['About 8', 'About 25', 'About 80', 'About 300'], 'c' => 2, 'e' => 'Around eighty, spanning his entire working life.'],
            ],
        ],
    ],
];
