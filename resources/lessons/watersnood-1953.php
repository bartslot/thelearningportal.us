<?php

declare(strict_types=1);

/** The Flood of 1953 and the Delta Works — the night the sea came in, and the answer to it. */
return [
    'key' => 'The Flood of 1953 and the Delta Works',
    'title' => 'The Flood of 1953 and the Delta Works',
    'subject' => 'history',
    'language' => 'nl',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [
        [
            'type' => 'quiz', 'when' => 'pre', 'chapter' => 'What do you already know?',
            'questions' => [
                ['q' => 'Roughly how much of the Netherlands lies below sea level?', 'o' => ['Almost none', 'About a quarter', 'About half', 'All of it'], 'c' => 1, 'e' => 'About a quarter of the country is below sea level, and around a half is flood-prone.'],
                ['q' => 'In which year did the great North Sea flood strike Zeeland?', 'o' => ['1928', '1953', '1963', '1975'], 'c' => 1, 'e' => 'The night of 31 January to 1 February 1953.'],
                ['q' => 'What are the Delta Works?', 'o' => ['A chain of dams and storm surge barriers', 'A shipping company', 'A group of museums', 'A type of windmill'], 'c' => 0, 'e' => 'Thirteen major works built to shorten and protect the Dutch coastline.'],
                ['q' => 'What is a "waterschap" (water board)?', 'o' => ['A boat', 'A local authority that manages dykes and water levels', 'A weather station', 'A canal'], 'c' => 1, 'e' => 'Dutch water boards are among the oldest democratic institutions in Europe.'],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'A country below the sea', 'location' => 'The Netherlands', 'year' => 1900,
            'image' => 'Nederlandse dijk polder landschap', 'prefer' => 'photo',
            'script' => 'The Netherlands exists because people decided the sea should be somewhere else. For a thousand years, communities here built dykes, dug drainage ditches, and pumped water uphill with windmills to turn marsh and lake into farmland. They organised themselves into water boards to do it — local authorities so old that some of them predate the Dutch state itself. It works. But it means that a quarter of the country sits below sea level, and everything depends on the dykes holding.',
        ],
        [
            'type' => 'story', 'chapter' => 'A bad combination', 'location' => 'The North Sea', 'year' => 1953,
            'image' => 'noordzee storm golven', 'prefer' => 'photo',
            'script' => 'On Saturday the thirty first of January 1953, three things happened at once. A deep storm swept down the North Sea driving water south. It arrived at spring tide, when tides are already at their highest. And the funnel shape of the southern North Sea squeezed all that water into a smaller and smaller space. In the delta of Zeeland the water rose more than five and a half metres above normal. The dykes there had not been properly maintained since the war, and many were simply too low.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'The night of 1 February', 'location' => 'Zeeland', 'year' => 1953,
            'title' => 'When the dykes gave way', 'date_label' => '31 January – 1 February 1953',
            'story' => 'It happened in the dark, in the middle of the night, on a weekend. There was no national alarm system: the radio had closed down for the night and most people were asleep. In more than a hundred and fifty places the dykes broke, and the sea poured into polders that lay metres below it. People climbed into attics and onto roofs in freezing water and waited, some for days. In the Netherlands 1,836 people died and around 200,000 hectares of land went under. The sea also killed more than 300 people in England and over 200 at sea. Whole villages in Zeeland, South Holland and North Brabant were destroyed.',
            'images' => [
                'watersnood 1953 Zeeland overstroming',
                'watersnoodramp 1953 overstroming',
                'watersnood 1953 dijkdoorbraak',
            ],
            'prefer' => 'photo',
            'script' => 'It happened in the dark, in the middle of the night, at the weekend. There was no national alarm system: the radio had closed down and most people were asleep. In more than a hundred and fifty places the dykes broke, and the sea poured into polders lying metres below it. People climbed into attics and onto roofs in freezing water and waited, some of them for days. In the Netherlands one thousand eight hundred and thirty six people died, and around two hundred thousand hectares of land went under.',
        ],
        [
            'type' => 'story', 'chapter' => 'The boy and the boat', 'location' => 'Nieuwerkerk', 'year' => 1953,
            'image' => 'watersnood 1953 reddingsboot', 'prefer' => 'photo',
            'script' => 'The rescue was improvised by whoever was nearby: fishermen, farmers, soldiers, and crews of small boats who rowed from roof to roof pulling people out of the water. Helicopters were used for the first time in a Dutch disaster. There is a story every Dutch child hears, about a young man at Nieuwerkerk who is said to have plugged a hole in a dyke with his own boat to stop it widening and save the village. Historians argue about the details. What is not in doubt is that thousands of ordinary people spent that night doing things like it.',
        ],
        [
            'type' => 'map', 'chapter' => 'Where the water came in', 'location' => 'The Netherlands', 'year' => 1953, 'qid' => 'Q55',
            'script' => 'Look at the south west corner of the country. This is the delta, where the Rhine, the Meuse and the Scheldt all reach the sea through a maze of islands and estuaries. That maze meant an enormous length of coastline to defend, and in 1953 it failed. Schouwen-Duiveland, Goeree-Overflakkee, Tholen — island after island went under. The answer, when it came, was radical: instead of raising every dyke, the Dutch decided to close the estuaries and shorten the coastline itself.',
            'labels' => [
                ['Zeeland — the worst hit', 3.8000, 51.6000],
                ['Oosterscheldekering', 3.6800, 51.6800],
                ['Rotterdam — Maeslantkering', 4.1500, 51.9500],
            ],
        ],
        [
            'type' => '3d', 'chapter' => 'The barrier', 'location' => 'Oosterscheldekering', 'year' => 1986,
            'sketchfab' => 'https://sketchfab.com/3d-models/oosterscheldekering-storm-surge-barrier-68e57a00049a4530a5c865762e33d327',
            'script' => 'This is the Oosterscheldekering, the Eastern Scheldt storm surge barrier, and it is nine kilometres long. Turn it around and look at what it actually is: sixty two enormous steel gates hanging between concrete piers, each pier as heavy as an office block, sunk onto a prepared seabed. Normally the gates stand open so the tide still flows and the salt marshes stay alive. When a storm surge is forecast, they close. It took ten years to build and it is often called the eighth wonder of the modern world.',
        ],
        [
            'type' => 'story', 'chapter' => 'Never again', 'location' => 'Zeeland', 'year' => 1958,
            'image' => 'Deltawerken bouw dam', 'prefer' => 'photo',
            'script' => 'Within three weeks of the flood the government appointed a Delta Committee, and its answer was extraordinary in scale. Rather than defend every kilometre of the old coastline, they would dam the estuaries shut and cut seven hundred kilometres off the length of coast that needed protecting. Thirteen major works were built over more than forty years. Along the way the safety standard was set by a calculation: the sea defences of Holland must withstand a storm so severe it happens on average once every ten thousand years.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'A change of mind', 'location' => 'The Netherlands', 'year' => 1976,
            'title' => 'The dam that became a gate', 'date_label' => '1970s',
            'story' => 'The original plan was to seal the Eastern Scheldt completely with a solid dam. Then, in the 1970s, fishermen and a new environmental movement pushed back hard: closing it would kill the tidal salt marshes and the oyster and mussel beds, and end a whole way of life. After a fierce national argument, the government changed the design to a barrier that stands open most of the time — far more difficult and roughly three times more expensive. It is a rare and important example of a country deciding that safety and nature both had to win, and paying for it.',
            'images' => ['Oosterscheldekering', 'Maeslantkering Rotterdam', 'Deltawerken Zeeland luchtfoto'],
            'prefer' => 'photo',
            'script' => 'The original plan was to seal the Eastern Scheldt with a solid dam. Then fishermen and a new environmental movement pushed back: closing it would kill the tidal marshes and the oyster and mussel beds. After a fierce national argument the design was changed to a barrier that stands open most of the time — far harder to build and about three times more expensive. It is a rare example of a country deciding that safety and nature both had to win, and paying for it.',
        ],
        [
            'type' => 'video', 'chapter' => 'The story in two minutes', 'location' => 'Zeeland',
            'youtube' => 'https://www.youtube.com/watch?v=q2CGpSuFhMU', 'controls' => true, 'fit' => 'fit',
            'script' => 'This is the official Canon van Nederland clip about the flood. Watch it, and think about what a country decides to build after something like this.',
        ],
        [
            'type' => 'story', 'chapter' => 'Why it still matters', 'location' => 'The Netherlands', 'year' => 2025,
            'image' => 'Maeslantkering storm surge barrier', 'prefer' => 'photo',
            'script' => 'The Delta Works made the Netherlands the world expert on living with water, and Dutch engineers are now consulted everywhere from New Orleans to Jakarta to Bangladesh. But the problem has not gone away. Sea levels are rising, storms are getting stronger, and the ground in the western Netherlands is still slowly sinking. The current thinking has shifted from fighting the water to making room for it — widening rivers, accepting controlled flooding in some places. The lesson of 1953 was never that the sea can be beaten. It was that it has to be taken seriously, every single year.',
        ],
        [
            'type' => 'quiz', 'when' => 'post', 'chapter' => 'What did you learn?',
            'questions' => [
                ['q' => 'What three things combined to cause the 1953 flood?', 'o' => ['A storm, a spring tide, and the funnel shape of the North Sea', 'An earthquake, rain and snow', 'A dam failure and a heatwave', 'A shipwreck and fog'], 'c' => 0, 'e' => 'A severe northwesterly storm arrived at spring tide, and the narrowing sea piled the water up.'],
                ['q' => 'Why were so many people caught unprepared?', 'o' => ['They ignored warnings', 'It struck at night at the weekend with no national alarm system', 'They were on holiday', 'The dykes were brand new'], 'c' => 1, 'e' => 'The radio had shut down for the night and there was no way to warn people.'],
                ['q' => 'Roughly how many people died in the Netherlands?', 'o' => ['About 180', 'About 1,800', 'About 18,000', 'About 180,000'], 'c' => 1, 'e' => '1,836 people died in the Netherlands alone.'],
                ['q' => 'What was the core idea of the Delta Works?', 'o' => ['Raise every dyke by one metre', 'Dam the estuaries and shorten the coastline', 'Move everyone inland', 'Build a wall around Amsterdam'], 'c' => 1, 'e' => 'Closing the estuaries cut about 700 km off the coast that needed defending.'],
                ['q' => 'Why was the Eastern Scheldt built as a gated barrier rather than a solid dam?', 'o' => ['It was cheaper', 'To save the tidal nature and the shellfish industry', 'To let ships through', 'To generate electricity'], 'c' => 1, 'e' => 'Environmental and fishing protests forced a costlier design that keeps the tide flowing.'],
                ['q' => 'What is the modern approach to Dutch water safety?', 'o' => ['Ignore the sea', 'Making room for the river as well as building barriers', 'Removing all dykes', 'Building only windmills'], 'c' => 1, 'e' => 'Room for the River widens floodplains instead of relying on ever-higher dykes alone.'],
            ],
        ],
    ],
];
