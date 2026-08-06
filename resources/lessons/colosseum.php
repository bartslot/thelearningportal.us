<?php

declare(strict_types=1);

/**
 * The Colosseum — who built it, who paid for it, and who it was for. Roughly ages 11–14.
 *
 * The building is usually taught as engineering trivia. This version follows the money and the
 * labour instead: it was paid for with the plunder of Jerusalem and raised in part by the people
 * enslaved in that same war, which is recoverable from the dedicatory inscription and is far more
 * interesting than the seating capacity. The point of view is a stonemason's, then a spectator's.
 *
 * All imagery is PINNED: Piranesi, Caffi, Cole, Catel, Locatelli, Alma-Tadema.
 */
return [
    'key' => 'The Colosseum',
    'title' => 'The Colosseum',
    'subject' => 'history',
    'grade_level' => '7',
    'map_style' => 'satellite',

    'scenes' => [

        [
            'type' => 'quiz',
            'when' => 'pre',
            'chapter' => 'What do you already know?',
            'questions' => [
                [
                    'q' => 'Roughly how many people could the Colosseum hold?',
                    'o' => ['About 5,000', 'About 20,000', 'About 50,000', 'About 200,000'],
                    'c' => 2,
                    'e' => 'Around fifty thousand, which is the size of a large modern football stadium.',
                ],
                [
                    'q' => 'What was the building actually called by the Romans?',
                    'o' => ['The Colosseum', 'The Flavian Amphitheatre', 'The Circus Maximus', 'The Pantheon'],
                    'c' => 1,
                    'e' => 'The Flavian Amphitheatre, after the family who built it. The nickname Colosseum came later.',
                ],
                [
                    'q' => 'How much did it cost to get in?',
                    'o' => ['A day\'s wages', 'A small coin', 'Nothing, it was free', 'Only rich people could attend'],
                    'c' => 2,
                    'e' => 'Entry was free. Tickets were given out, and where you sat depended entirely on who you were.',
                ],
                [
                    'q' => 'What is underneath the arena floor?',
                    'o' => ['Solid rock', 'A lake', 'Two levels of tunnels, cages and lifts', 'Nothing, it was never excavated'],
                    'c' => 2,
                    'e' => 'The hypogeum: a two-storey basement with animal cages and around twenty eight lifts worked by winches.',
                ],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'The lake that was in the way',
            'location' => 'Rome',
            'year' => 70,
            'image' => 'commons:Andrea Locatelli Veduta del Colosseo.jpg',
            'prefer' => 'art',
            'script' => 'To understand this building you have to know what stood there first. After the great fire of Rome in 64 AD, the emperor Nero took a huge area of the burnt centre of the city, right in the middle of where ordinary Romans had lived, and built himself a palace with parkland, woods and an artificial lake. People called it the Golden House. Romans hated it, and they said so: one piece of graffiti ran that Rome was becoming one single house, and that everyone should move out to the countryside. Nero killed himself in 68 AD. There was a year of civil war, and a general called Vespasian came out on top. He needed to show Rome that the new family was nothing like the old one. So he drained Nero\'s private lake, and on that exact spot he began to build the largest public entertainment venue in the world, and gave it to the people. It was propaganda made of stone, and it was extremely good propaganda.',
        ],

        [
            'type' => 'map',
            'chapter' => 'Who paid for it',
            'location' => 'The Roman Empire',
            'year' => 71,
            'projection' => 'mercator',
            'script' => 'Now follow the money, because this is the part usually left out. In 66 AD the Jewish population of Judaea rebelled against Roman rule. Vespasian and his son Titus put that revolt down, and in the year 70 they besieged and took Jerusalem, destroyed the Temple, and carried its treasures back to Rome in a triumphal parade. Tens of thousands of people were enslaved. And in 1995 a historian called Géza Alföldy worked out the wording of the Colosseum\'s lost dedication by studying the holes where its bronze letters had been pegged into the stone. The reconstructed inscription says the amphitheatre was built from the spoils of war. The distance between these two points on the map is the distance between a destroyed city and a new stadium. Some of the people who put up these stones had been marched here from the other end of that line.',
            'labels' => [
                ['Rome', 12.50, 41.90],
                ['Jerusalem', 35.23, 31.78],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'In the shoes of the man who built it',
            'location' => 'Rome',
            'year' => 75,
            'image' => 'commons:Giovanni Battista Piranesi, Colosseum, 1760, KKSgb9860-57, Statens Museum for Kunst.jpg'
                ,
            'prefer' => 'art',
            'script' => 'Stand in the mud of the building site for a moment. You are a stonemason, and you have been on this job for years. The stone is travertine, quarried at Tivoli about thirty kilometres away, and it comes in by ox cart along a road widened specially for it, day after day after day, one hundred thousand cubic metres of it in the end. Your job is to cut blocks so they sit against each other with no mortar at all, held instead by iron clamps poured into channels: about three hundred tonnes of iron in the finished building. You work with hand tools. There are no cranes except wooden ones turned by men walking inside a wheel. Some of the people working beside you are free craftsmen being paid, and some are enslaved, and a number of those were brought here from Judaea. In roughly eight years you will help raise a four storey oval of stone fifty metres high, with eighty arched entrances, that can fill and empty faster than most stadiums built in your own lifetime. And no one will ever write down your name.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'A machine for holding a city',
            'location' => 'Rome',
            'year' => 80,
            'title' => 'How the building actually worked',
            'date_label' => 'Opened in 80 AD',
            'story' => 'Everything about it was designed to move fifty thousand people quickly and to keep them in their place. There were eighty entrance arches, seventy six of them numbered, and spectators carried a token telling them which arch, which staircase and which row. The building could reportedly be emptied in a matter of minutes. Where you sat was decided by law. Senators sat at the front on marble, then knights, then ordinary male citizens, then, at the very top and furthest from the sand, women, the poor and the enslaved. You could read the entire structure of Roman society by looking at the seating from below. Over the top ran the velarium, an enormous cloth awning worked with ropes and masts by sailors brought in from the imperial fleet at Misenum, because they were the only people in Italy trained to handle that much rigging. And under the floor was the hypogeum: two storeys of tunnels holding animals and scenery, with around twenty eight lifts that could raise a cage and drop a trapdoor so a lion appeared in the middle of the arena as if from nowhere.',
            'images' => [
                'commons:Ippolito Caffi, Interior of the Colosseum, NGA 143645.jpg',
                'commons:Cole Thomas Interior of the Colosseum Rome 1832.jpg',
                'commons:Giovanni Battista Piranesi, Colosseum, 1760, KKSgb9860-57, Statens Museum for Kunst.jpg',
            ],
            'prefer' => 'art',
            'script' => 'Everything about it was built to move fifty thousand people fast and to keep them exactly where they belonged. Eighty entrance arches, most of them numbered. Your token told you which arch, which staircase, which row. Where you sat was set by law: senators at the front on marble, then knights, then ordinary citizens, and at the very top, furthest from the sand, women, the poor and the enslaved. You could read the whole shape of Roman society just by looking up at the seats. Across the top ran a vast cloth awning worked by sailors brought in from the naval base at Misenum, because they were the only men in Italy who could handle that much rope. And beneath the floor lay two storeys of tunnels with around twenty eight lifts, so that a cage could be winched up and a trapdoor opened and a lion would appear in the middle of the arena as if from nowhere.',
        ],

        [
            'type' => 'story',
            'chapter' => 'A hundred days',
            'location' => 'Rome',
            'year' => 80,
            'image' => 'commons:Sir Lawrence Alma-Tadema, RA, OM - The Triumph of Titus - The Flavians - Walters 3731.jpg',
            'prefer' => 'art',
            'script' => 'Vespasian died before it was finished, so it was his son Titus who opened the building in the year 80 with a hundred days of games. Our sources say around nine thousand animals were killed in that opening season: bears, lions, elephants, hippos, creatures most Romans had never seen and would never see again. There were gladiator fights, executions at midday, hunts in the morning, and reportedly a staged sea battle, which would have meant flooding the arena, and which historians still argue about because the drains and the basement make it very difficult. Now hold two facts side by side. This was a gift to the people of Rome, free of charge, and Romans genuinely loved it. And it was a hundred days of killing, paid for with the plunder of a destroyed city, in a building put up partly by the survivors of that same war. Both of those are true, and the Romans saw no contradiction at all.',
        ],

        [
            'type' => 'story',
            'chapter' => 'In the shoes of someone in the crowd',
            'location' => 'Rome',
            'year' => 80,
            'image' => 'commons:The interior of the Colosseum illuminated by fireworks (by Ippolito Caffi).jpg',
            'prefer' => 'art',
            'script' => 'Now sit down in the seats. You are a baker, or a porter, or a boy who carries water. You are poor, so you are near the top, and the figures on the sand are small. It is hot, and the awning is out, and the whole crowd is in shifting shade. There is sand underfoot to soak up what will be spilled, and the Latin word for sand is harena, which is where we get the word arena. You will be here all day and it costs you nothing. There is free food thrown into the crowd, and lots being drawn for prizes. Everyone around you knows the fighters\' names, argues about them, and has money on the outcome. And at some point the crowd will shout together about whether a man on the ground should live, and you will shout with them, because everybody is shouting, and because it does not feel like a decision when fifty thousand people make it at once. That is the thing worth understanding about this building. It was not designed to turn people into monsters. It was designed to make an ordinary person feel completely normal while doing something monstrous.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'The long afterwards',
            'location' => 'Rome',
            'year' => 1750,
            'title' => 'Fortress, church, quarry, ruin',
            'date_label' => '500 to 1800',
            'story' => 'The games stopped in the fifth century, and the building began a second life that lasted far longer than its first. It became a fortress for a Roman family. Parts of it housed workshops and homes, and a small church. Earthquakes in 1231 and especially 1349 brought down the whole southern outer wall, which is why the building looks lopsided today: the intact side is the original outer ring, and the ragged side is the inside of the building, exposed. Then Rome quarried it. Travertine from the Colosseum went into palaces, churches and, according to long tradition, into the fabric of St Peter\'s. Medieval workers also dug out the iron clamps that held the blocks together, which is why the stone is covered in thousands of small pits: those holes are where the iron was chiselled out. In 1749 the Pope declared the site sacred to Christian martyrs and the demolition stopped. Whether large numbers of Christians actually died there is doubted by many historians. It is possible that a belief with thin evidence behind it is the reason the building survived at all.',
            'images' => [
                'commons:Franz Ludwig Catel - Parti af Colosseum i måneskin.jpg',
                'commons:Andrea Locatelli Veduta del Colosseo.jpg',
                'commons:Ippolito Caffi, Interior of the Colosseum, NGA 143645.jpg',
            ],
            'prefer' => 'art',
            'script' => 'The games stopped in the fifth century, and then the building had a second life that lasted much longer than its first. It became a fortress. It held workshops, homes and a small church. Earthquakes in 1231 and 1349 brought down the entire southern outer wall, and that is why it looks lopsided now: one side is the original outside, and the ragged side is the inside of the building, laid open. Then Rome mined it. Its stone went into palaces and churches across the city. Medieval workers chiselled out the iron clamps holding the blocks together, and the thousands of small holes you can see all over the stonework are exactly where that iron was dug out. In 1749 the Pope declared the arena sacred to Christian martyrs, and the quarrying stopped. Historians doubt that many Christians actually died there. So it is quite possible that this building is still standing because of a belief that may not be true.',
        ],

        [
            'type' => 'story',
            'chapter' => 'Why the painters came',
            'location' => 'Rome',
            'year' => 1800,
            'image' => 'commons:Franz Ludwig Catel - Parti af Colosseum i måneskin.jpg',
            'prefer' => 'art',
            'script' => 'For about three hundred years the Colosseum was a ruin full of plants. Botanists catalogued more than four hundred species growing in it, some of which had probably arrived in the fur and feed of animals brought from Africa and Asia. And every painter and poet who came to Rome came here, usually at night, usually by moonlight, because a broken building says something a whole one cannot. The pictures in this lesson are theirs. When you look at Piranesi\'s enormous etchings, or Catel\'s moonlight, you are not looking at the Roman Colosseum. You are looking at what people much later felt about it: that empires end, that nothing built by anyone lasts, and that this is somehow beautiful. Those are eighteenth century feelings wearing Roman clothes. It is worth being able to tell the difference between a building and the mood we have decided to attach to it.',
        ],

        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'What did you learn?',
            'questions' => [
                [
                    'q' => 'What stood on the site of the Colosseum before it was built?',
                    'o' => [
                        'A temple to Jupiter',
                        'The artificial lake of Nero\'s private palace',
                        'A gladiator school',
                        'The Roman Senate house',
                    ],
                    'c' => 1,
                    'e' => 'Vespasian drained Nero\'s private lake and gave the land back to the public as a deliberate political statement.',
                ],
                [
                    'q' => 'How do we know the building was paid for with the spoils of war?',
                    'o' => [
                        'A Roman historian says so',
                        'From the peg holes of the lost bronze dedication, reconstructed in 1995',
                        'It is carved on Trajan\'s Column',
                        'We do not know, it is a guess',
                    ],
                    'c' => 1,
                    'e' => 'Géza Alföldy reconstructed the inscription from the holes where its bronze letters were fixed, and it records that it was built from the spoils.',
                ],
                [
                    'q' => 'What decided where a Roman sat in the Colosseum?',
                    'o' => [
                        'How early they arrived',
                        'How much they paid',
                        'Their rank in society, which was set by law',
                        'They could sit anywhere',
                    ],
                    'c' => 2,
                    'e' => 'Senators at the front, then knights, then citizens, with women, the poor and the enslaved at the very top.',
                ],
                [
                    'q' => 'Why is the Colosseum lopsided today?',
                    'o' => [
                        'It was never finished',
                        'Earthquakes brought down the southern outer wall, and stone was quarried away',
                        'It was bombed',
                        'It was built that way on purpose',
                    ],
                    'c' => 1,
                    'e' => 'The 1349 earthquake destroyed the southern outer ring, and Romans then quarried the fallen stone for other buildings.',
                ],
                [
                    'q' => 'What are the thousands of small holes in the stonework?',
                    'o' => [
                        'Damage from weapons',
                        'Places where medieval workers dug out the iron clamps',
                        'Nesting holes for birds',
                        'Decoration',
                    ],
                    'c' => 1,
                    'e' => 'The blocks were held by around three hundred tonnes of iron clamps, which were chiselled out for reuse in the Middle Ages.',
                ],
                [
                    'q' => 'Why did the destruction of the Colosseum eventually stop?',
                    'o' => [
                        'The city ran out of workers',
                        'A Pope declared it sacred to Christian martyrs in 1749',
                        'It was made a royal palace',
                        'An emperor forbade it',
                    ],
                    'c' => 1,
                    'e' => 'The consecration in 1749 protected it, even though historians doubt that many Christians actually died there.',
                ],
            ],
        ],
    ],
];
