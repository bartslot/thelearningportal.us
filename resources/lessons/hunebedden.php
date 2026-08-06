<?php

declare(strict_types=1);

/** The Hunebedden — the oldest man-made things in the Netherlands. */
return [
    'key' => 'The Hunebedden: the oldest monuments in the Netherlands',
    'title' => 'The Hunebedden: the oldest monuments in the Netherlands',
    'subject' => 'history',
    'language' => 'nl',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [
        [
            'type' => 'quiz', 'when' => 'pre', 'chapter' => 'What do you already know?',
            'questions' => [
                ['q' => 'Roughly how old are the hunebedden?', 'o' => ['About 500 years', 'About 1,500 years', 'About 5,500 years', 'About 50,000 years'], 'c' => 2, 'e' => 'They were built around 3500 BC — older than Stonehenge and the Egyptian pyramids.'],
                ['q' => 'What were hunebedden used for?', 'o' => ['Houses', 'Communal graves', 'Cattle pens', 'Lookout towers'], 'c' => 1, 'e' => 'They are burial chambers, used by a whole community over generations.'],
                ['q' => 'In which Dutch province are almost all hunebedden found?', 'o' => ['Zeeland', 'Limburg', 'Drenthe', 'Friesland'], 'c' => 2, 'e' => 'Fifty two of the fifty four Dutch hunebedden are in Drenthe.'],
                ['q' => 'Where did the enormous boulders come from?', 'o' => ['Local quarries', 'Carried from Scandinavia by glaciers', 'Shipped from Egypt', 'They were made of concrete'], 'c' => 1, 'e' => 'Ice sheets dragged them from Scandinavia during an ice age, then left them behind.'],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'Stones the ice left behind', 'location' => 'Drenthe', 'year' => -150000,
            'image' => 'commons:D 6 - Hunebed bij Tynaarlo (Drenthe).jpg', 'prefer' => 'photo',
            'script' => 'Long before there were any people here, there was ice. During an ice age, a glacier a kilometre thick ground its way south out of Scandinavia, carrying rock with it. When the ice finally melted, it dropped what it was carrying: thousands of granite boulders, some weighing more than twenty tonnes, scattered across the sandy ridge we now call the Hondsrug in Drenthe. They sat there for a hundred and fifty thousand years, waiting.',
        ],
        [
            'type' => 'story', 'chapter' => 'The first farmers', 'location' => 'Drenthe', 'year' => -3500,
            'image' => 'neolithicum boerderij reconstructie', 'prefer' => 'photo',
            'script' => 'Around five and a half thousand years ago, people in this region stopped moving with the seasons and settled down. They cleared woodland, planted emmer wheat and barley, kept cattle, pigs and sheep, and made distinctive pottery decorated with lines that look like a funnel — which is why archaeologists call them the Funnelbeaker culture. Once you farm, you stay in one place. And once you stay in one place, your dead stay with you.',
        ],
        [
            'type' => '3d', 'chapter' => 'How a hunebed is built', 'location' => 'A hunebed',
            'sketchfab' => 'https://sketchfab.com/3d-models/hunebed-d45-with-color-0fcf3cc6dd8d401e8ae248cce2e6fad0',
            'script' => 'This is a laser scan of hunebed D45 at Emmen — a real Dutch hunebed you can go and stand next to today. Turn it around and you can see how it works. Big upright stones form two walls. Enormous capstones are laid across the top like a roof. A short passage leads in from one side, and the whole thing was originally buried under a mound of earth and smaller stones, so what you see today is a skeleton. No mortar, no metal tools, no wheel, no writing. Just stone, timber, rope and an enormous amount of organised human effort.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'Moving twenty tonnes', 'location' => 'Drenthe', 'year' => -3400,
            'title' => 'How did they do it?', 'date_label' => 'around 3500 BC',
            'story' => 'Archaeologists have tested the theories by trying them. The most likely method used timber rollers and sledges to drag a boulder across the sand, ropes made of plant fibre and hide, and levers to lift each capstone a few centimetres at a time onto a rising ramp of earth and logs. Estimates suggest a single hunebed needed dozens of adults working together for weeks, plus enough surplus food to feed them while they were not farming. That is the real discovery here: not the engineering, but the fact that these scattered farming families could organise themselves on that scale at all.',
            'images' => ['hunebed Drenthe stenen', 'hunebed D27 Borger', 'hunebedcentrum Borger'],
            'prefer' => 'photo',
            'script' => 'Archaeologists have tested the theories by trying them. The likely method used timber rollers and sledges to drag a boulder across the sand, ropes of plant fibre and hide, and levers to lift each capstone a few centimetres at a time onto a rising ramp of earth and logs. One hunebed needed dozens of adults working for weeks, plus enough spare food to feed them while they were not farming. That is the real discovery: not the engineering, but that scattered farming families could organise themselves on that scale at all.',
        ],
        [
            'type' => 'map', 'chapter' => 'Where they stand', 'location' => 'The Netherlands', 'year' => -3500, 'qid' => 'Q55',
            'script' => 'Almost every Dutch hunebed stands on one long sandy ridge in the province of Drenthe, in the north east. That is not a coincidence: the ridge is exactly where the glacier dumped its boulders, and it was dry, workable land in a country that was mostly bog and marsh. Fifty two of the fifty four are here. Borger has the largest of them all, and the national hunebed museum.',
            'labels' => [
                ['Borger — the largest hunebed', 6.7986, 52.9236],
                ['Havelte — D53', 6.2333, 52.7833],
                ['Emmen', 6.9069, 52.7792],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'A grave for everyone', 'location' => 'Drenthe', 'year' => -3300,
            'image' => 'trechterbeker aardewerk neolithicum', 'prefer' => 'photo',
            'script' => 'A hunebed was not one person\'s tomb. Excavations have found the remains of dozens, sometimes hundreds of people in a single chamber, added over centuries — along with pottery, flint tools and amber beads placed with them. Because the Drenthe soil is acidic, most of the bone has dissolved away, and what survives is mostly the pots. Nobody was buried alone in a monument like this. That says something about how these people saw themselves: as part of a group, in life and after it.',
        ],
        [
            'type' => 'story', 'chapter' => 'Giants and free stone', 'location' => 'Drenthe', 'year' => 1734,
            'image' => 'Titia Brongersma hunebed', 'prefer' => 'art',
            'script' => 'For centuries nobody knew what these things were. The name hunebed probably comes from an old word meaning giant\'s bed, because who else could have piled up stones like that? People also treated them as a free supply of building material: hunebedden were broken up for church foundations, dykes and roads. In 1734 the province of Drenthe passed a law protecting them — one of the earliest heritage protection laws anywhere in the world. Of an estimated eighty or more, fifty four survived.',
        ],
        [
            'type' => 'video', 'chapter' => 'See one for yourself', 'location' => 'Drenthe',
            'youtube' => 'https://www.youtube.com/watch?v=z3YptV9L3Wo', 'controls' => true, 'fit' => 'fit',
            'script' => 'This is the official Canon van Nederland clip about the hunebedden. Think about how much archaeologists can work out from a heap of stones and a few broken pots.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'Older than the pyramids', 'location' => 'Europe', 'year' => -3000,
            'title' => 'Putting 3500 BC in order', 'date_label' => 'Neolithic Europe',
            'story' => 'It is worth getting the order right, because it is surprising. The hunebedden were built around 3500 BC. Stonehenge\'s first stone circle went up around 3000 BC. The Great Pyramid of Giza was built around 2560 BC. So when the pharaohs\' architects started work, the hunebedden had already been standing for a thousand years. They are part of a whole megalithic tradition that stretches along the Atlantic and North Sea coasts of Europe, from Portugal to Denmark — thousands of people, in dozens of communities, moving giant stones for their dead.',
            'images' => ['dolmen megalith Europe', 'Stonehenge', 'megalithic tomb Denmark'],
            'prefer' => 'photo',
            'script' => 'It is worth getting the order right, because it is surprising. The hunebedden were built around 3500 BC. Stonehenge\'s first stone circle went up around 3000 BC. The Great Pyramid was built around 2560 BC. So when the pharaohs\' architects started work, the hunebedden had already stood for a thousand years. They belong to a whole megalithic tradition stretching along the Atlantic coasts of Europe, from Portugal to Denmark.',
        ],
        [
            'type' => 'story', 'chapter' => 'What we still cannot say', 'location' => 'Drenthe', 'year' => 2020,
            'image' => 'hunebed landschap Drenthe', 'prefer' => 'photo',
            'script' => 'These people left no writing, so there is a great deal we will never know. We do not know what language they spoke, what they called themselves, what they believed happened after death, or why they stopped building hunebedden after roughly five hundred years. What we do have is the stones, the pots, and the shape of the effort. When you stand next to one, the thing worth thinking about is not the mystery. It is that a small group of farmers with no metal and no wheel decided their dead deserved something that would still be standing five and a half thousand years later — and they were right.',
        ],
        [
            'type' => 'quiz', 'when' => 'post', 'chapter' => 'What did you learn?',
            'questions' => [
                ['q' => 'How did the huge boulders reach Drenthe?', 'o' => ['Glaciers carried them from Scandinavia', 'Romans shipped them in', 'They were dug from local quarries', 'They formed from volcanoes'], 'c' => 0, 'e' => 'An ice-age glacier dragged them south and dropped them when it melted.'],
                ['q' => 'Which culture built the hunebedden?', 'o' => ['The Romans', 'The Vikings', 'The Funnelbeaker farmers', 'The Celts'], 'c' => 2, 'e' => 'Named after their distinctive funnel-shaped pottery.'],
                ['q' => 'How were hunebedden used as graves?', 'o' => ['One important chief per tomb', 'Whole communities buried together over centuries', 'Only children', 'They were never used for burial'], 'c' => 1, 'e' => 'Dozens or even hundreds of people were placed in a single chamber over generations.'],
                ['q' => 'Why does so little bone survive inside them?', 'o' => ['Grave robbers took it', 'The acidic sandy soil dissolved it', 'It was burned', 'It was moved to museums'], 'c' => 1, 'e' => 'Drenthe\'s acidic soil destroys bone, so mostly pottery survives.'],
                ['q' => 'Which is oldest?', 'o' => ['The Great Pyramid of Giza', 'Stonehenge\'s stone circle', 'The hunebedden', 'The Colosseum'], 'c' => 2, 'e' => 'The hunebedden (about 3500 BC) predate Stonehenge and the pyramids.'],
                ['q' => 'What happened to many hunebedden before 1734?', 'o' => ['They sank into the sea', 'They were broken up for building material', 'They were moved to Germany', 'They were buried by farmers'], 'c' => 1, 'e' => 'They were quarried for churches, dykes and roads until Drenthe protected them by law.'],
            ],
        ],
    ],
];
