<?php

declare(strict_types=1);

/**
 * The Punic Wars — Rome and Carthage, 264 to 146 BC. Written for roughly ages 11–14.
 *
 * The spine is Hannibal's march (the hannibal-218 voyage), because a route drawn across a map is
 * the one way a child feels how absurd that journey was. Where the lesson asks a class to imagine
 * being there it says so out loud, and then goes straight back to what the sources actually record:
 * the point is empathy grounded in evidence, not invented dialogue.
 *
 * Imagery is PINNED to named paintings. Free-text search for these subjects returns battle
 * schematics and modern concrete statues, which is worse than no picture at all.
 *
 * Every stop carries at least three pictures, and no picture appears at two different stops. The
 * one exception is deliberate: Turner's "Dido Building Carthage" opens the lesson and closes it.
 * The rule exists because the Ripanda fresco used to fill the second slot of three consecutive
 * legs, which reads as the lesson having run out of things to show.
 */
return [
    'key' => 'The Punic Wars',
    'title' => 'The Punic Wars',
    'subject' => 'history',
    'language' => 'en',
    'grade_level' => '7',
    'map_style' => 'satellite',

    'scenes' => [

        // ── Prior knowledge ──────────────────────────────────────────────
        [
            'type' => 'quiz',
            'when' => 'pre',
            'chapter' => 'What do you already know?',
            // Prior knowledge only, and deliberately NOT the ending. The explanation is shown to the
            // class the moment a question is answered, so anything stated here is stated in scene 1
            // of 19: an outcome question spoils fifteen scenes of story before the story starts.
            // Options are kept to the same visual length for the reason given on the post quiz.
            'questions' => [
                [
                    'q' => 'The Punic Wars were fought between Rome and which other city?',
                    'o' => ['Athens', 'Carthage', 'Troy', 'Alexandria'],
                    'c' => 1,
                    'e' => 'Carthage. The Romans called its people Poeni, which is where the word Punic comes from.',
                ],
                [
                    'q' => 'Which general famously marched an army with elephants over the Alps?',
                    'o' => ['Julius Caesar', 'Hannibal Barca', 'Scipio Africanus', 'Alexander the Great'],
                    'c' => 1,
                    'e' => 'Hannibal Barca, in the autumn of 218 BC. Two of the others are in this story as well.',
                ],
                [
                    'q' => 'Where was Carthage?',
                    'o' => [
                        'On the coast of North Africa',
                        'On the coast of southern Spain',
                        'On the coast of southern Greece',
                        'On the island of Sicily',
                    ],
                    'c' => 0,
                    'e' => 'North Africa, on the gulf beside modern Tunis. Close enough to Sicily that both cities wanted the island.',
                ],
                [
                    'q' => 'How long did Rome and Carthage fight, on and off?',
                    'o' => ['About 12 years', 'About 40 years', 'About 70 years', 'About 120 years'],
                    'c' => 3,
                    'e' => 'Three separate wars between 264 and 146 BC: a hundred and eighteen years. The grandchildren of the first war fought the last one.',
                ],
            ],
        ],

        // ── Two cities ───────────────────────────────────────────────────
        [
            'type' => 'story',
            'chapter' => 'Two cities, one sea',
            'location' => 'Carthage',
            'year' => -300,
            'image' => 'commons:Turner Dido Building Carthage.jpg',
            'prefer' => 'art',
            'script' => 'Picture the Mediterranean two and a half thousand years ago as a single busy lake with two great cities on opposite shores. In North Africa stood Carthage: a harbour city of traders and sailors, rich beyond counting, with a war fleet and a circular dock that could hold two hundred ships. Across the water in Italy stood Rome: smaller, poorer, with almost no navy at all, but with something Carthage did not have. Rome could raise farmers into soldiers year after year after year, and it did not know how to stop. For a long time the two cities were friends. They signed treaties. They traded. Then they both wanted Sicily.',
        ],
        [
            'type' => 'story',
            'chapter' => 'The first war: Rome learns to swim',
            'location' => 'Sicily',
            'year' => -260,
            'image' => "commons:Claude Lorrain - Seaport with the Embarkation of the Queen of Sheba - WGA05002.jpg",
            'prefer' => 'art',
            'script' => 'The first war began in 264 BC over a quarrel in Sicily, and it lasted twenty three years. Carthage ruled the sea and Rome had nothing to fight it with. So the Romans did something strange. They took a wrecked Carthaginian warship that had run aground, copied it plank by plank, and built a fleet of their own. Then, because their crews could not sail as well as the Carthaginians, they invented a boarding bridge with a spike on the end, dropped it onto enemy decks, and turned every sea battle into a land battle. It worked. Rome lost hundreds of ships and tens of thousands of men to storms, and simply built more. In 241 BC Carthage gave up Sicily and paid an enormous fine. That is the pattern to remember: Rome could lose again and again and still not lose.',
        ],
        [
            'type' => 'story',
            'chapter' => 'A promise made by a nine year old',
            'location' => 'Carthage',
            'year' => -237,
            'image' => 'commons:Hannibal in Italy, Jacopo Ripanda and assistants, 1507-1508, fresco - Musei Capitolini - Rome, Italy - DSC05922.jpg',
            'prefer' => 'art',
            'script' => 'A Carthaginian general called Hamilcar Barca came home from that defeat furious. He took his armies to Spain instead, to build a new empire there out of silver mines and soldiers. And the Greek historian Polybius tells us that before he sailed, Hamilcar took his nine year old son to an altar, put the boy\'s hand on the sacrifice, and made him swear that he would never be a friend to Rome. The boy was called Hannibal. We cannot check that story, because Hannibal told it himself many years later, and he had good reasons to tell it. But everything he did for the rest of his life fits it exactly.',
        ],

        // ── Where this happened ──────────────────────────────────────────
        [
            'type' => 'map',
            'chapter' => 'Where it happened',
            'location' => 'The western Mediterranean',
            'year' => -218,
            'projection' => 'mercator',
            'script' => 'Look at the shape of this problem. Down here is Carthage, in North Africa. Up here is Rome, in the middle of Italy. Between them lies Sicily, the island they fought the first war over. And far away to the west, in Spain, is New Carthage, the city Hannibal\'s family built as the base of their new empire. Now find the sea route from Spain to Italy, and notice who owns it. Rome does. Every ship Hannibal put on that water could be sunk. So if he wanted to attack Rome, he could not go by sea. He would have to walk.',
            'labels' => [
                ['New Carthage', -0.98, 37.60],
                ['Carthage', 10.32, 36.85],
                ['Rome', 12.50, 41.90],
                ['Sicily', 14.15, 37.50],
            ],
        ],

        // ── The march ────────────────────────────────────────────────────
        [
            'type' => 'voyage', 'voyage' => 'hannibal-218', 'leg' => 0, 'intro' => true,
            'chapter' => 'The army sets out',
            'location' => 'New Carthage',
            'year' => -218,
            'title' => 'A city called New Carthage',
            'date_label' => 'Spring, 218 BC',
            'story' => 'Hannibal left New Carthage in the spring of 218 BC with something in the region of ninety thousand infantry, twelve thousand cavalry and thirty seven elephants. Nobody had ever attempted anything like it. He was twenty eight years old.',
            'images' => [
                'commons:Hannibal Barca bust from Capua photo.jpg',
                'commons:Guerrero ibero de La Alcudia (M.A.N. Inv.38441) 01.jpg',
                'commons:Muralla Púnica de Cartagena.jpg',
            ],
            'prefer' => 'art',
            'script' => 'In the spring of 218 BC, Hannibal marched out of New Carthage heading north. With him went something like ninety thousand foot soldiers, twelve thousand horsemen, and thirty seven elephants. He was twenty eight years old. He was not marching to raid the coast or take a town. He intended to walk fifteen hundred kilometres, cross two mountain ranges, and attack the most powerful city in the world in its own back garden. Watch the line on the map, and keep asking yourself the same question the Romans never thought to ask: could anyone really come that way?',
        ],
        [
            'type' => 'voyage', 'voyage' => 'hannibal-218', 'leg' => 1,
            'chapter' => 'Over the Pyrenees',
            'location' => 'The Pyrenees',
            'year' => -218,
            'title' => 'The first mountains',
            'date_label' => 'Summer, 218 BC',
            'story' => 'Crossing the Pyrenees cost Hannibal thousands of men before he had fought a single battle. Some died. Many more simply went home: these were soldiers from Spain who had signed up to fight in Spain, and the mountains were where they understood what they had joined.',
            'images' => [
                'commons:James Dickson Innes - Deep Twilight, Pyrenees - Google Art Project.jpg',
                'commons:Stanislas von Kalckreuth - Hochgebirgslandschaft in den Pyrenäen (1857).jpg',
                'commons:Eiler Rasmussen Eilersen - En bjergvej i Pyrenæerne.png',
            ],
            'prefer' => 'art',
            'script' => 'The Pyrenees came first, and they cost him dearly. Some men died on those slopes. Far more of them walked away. They were soldiers recruited in Spain to fight in Spain, and somewhere on that climb it dawned on them how far this general actually meant to go. Hannibal did something clever and rather cold: he let them leave, and he sent home a further ten thousand he did not trust, so that only the willing walked on. An army that wants to be there is worth more than a bigger one that does not. By the time he came down into Gaul he had lost roughly a third of the men he set out with, and had not yet seen a Roman.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'hannibal-218', 'leg' => 2,
            'chapter' => 'Elephants on the river',
            'location' => 'The Rhône',
            'year' => -218,
            'title' => 'Getting an elephant across a river',
            'date_label' => 'September, 218 BC',
            'story' => 'To cross the Rhône, Hannibal built enormous rafts, covered them with earth and turf until they looked like solid ground, and led the elephants on. When the rafts were cut loose and began to drift, the animals panicked. Some threw themselves into the water and waded across on the riverbed with only their trunks above the surface.',
            // The map walks a HORSE across this leg (transports.json has no elephant model), so the
            // elephants have to arrive as pictures or the scene contradicts its own title.
            'images' => [
                'commons:Hannibal traverse le Rhône Henri Motte 1878.jpg',
                'commons:Carl Emil Doepler - The elephants crossing the Rhone, 218 BC.jpg',
                'commons:Carthage, quarter shekel, 237-209 BC, SNG BM Spain 102.jpg',
            ],
            'prefer' => 'art',
            'script' => 'Then came the Rhône, wide and fast, with hostile Gauls waiting on the far bank. Hannibal sent part of his force upstream in the night to cross secretly and come at the Gauls from behind, and while they were busy he put his army over. The elephants were the hard part. He built huge rafts, piled them with earth and turf until they looked like ordinary ground, and walked the animals on. When the ropes were cut and the rafts began to move, the elephants realised. Some panicked and went into the river, and according to our sources they crossed by walking along the bottom with only their trunks lifted above the water. Look at the painting. That is a general improvising, in a foreign country, with an animal that weighs four tonnes and has just worked out it has been tricked.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'hannibal-218', 'leg' => 4,
            'chapter' => 'The Alps',
            'location' => 'The Alps',
            'year' => -218,
            'title' => 'Fifteen days that made him famous',
            'date_label' => 'October to November, 218 BC',
            'story' => 'The crossing took about fifteen days, in late October, with the first snow already down. Mountain tribes rolled rocks onto the column from above. Pack animals slipped off ledges. On the descent the army met a landslide that had taken the path away entirely, and had to cut a new road through the rock while the men waited in the open with nothing to eat.',
            // Poussin FIRST, deliberately: it is the one picture in the lesson where you can see
            // Hannibal on the elephant, and it is also the map's thumbnail for this landfall.
            // Turner's snowstorm is magnificent and shows almost nothing — it earns second place.
            'images' => [
                "commons:Hannibal traversant les Alpes à dos d'éléphant - Nicolas Poussin.jpg",
                'commons:Joseph Mallord William Turner - Snow Storm, Hannibal and his Army Crossing the Alps - WGA23167.jpg',
                'commons:Heinrich Leutemann - Hannibals Übergang über die Alpen.jpg',
                'commons:Loutherbourg-Une avalanche de glace dans les Alpes.jpg',
            ],
            'prefer' => 'art',
            'script' => 'And then the Alps. He went up in late October, which is far too late, with the first snow already lying on the passes. It took about fifteen days. Tribes who knew the ground rolled boulders down onto the column from above, and there was no way to fight back at a man standing on a cliff. Horses lost their footing on ice and took their handlers over the edge with them. On the way down the army came to a place where a landslide had removed the path completely, and they had to cut a new road out of the rock while thousands of men stood waiting in the cold with nothing left to eat. Turner painted this, and notice what he chose to make the enemy. Not Romans. The weather.',
        ],

        // ── In the shoes of a soldier ────────────────────────────────────
        [
            'type' => 'story',
            'chapter' => 'What it cost',
            'location' => 'Northern Italy',
            'year' => -218,
            'image' => "commons:Chatel Argent and the Val d'Aosta from above Villeneuve MET DP805110.jpg",
            'prefer' => 'art',
            'script' => 'Stop the map for a moment and stand in the column. You are not Hannibal. You are one of the ordinary ones: a Libyan spearman, or a Spaniard from a village near the silver mines, or a Gaul who joined three weeks ago. You have been walking since spring. Your boots went somewhere near the Pyrenees. You have watched friends fall off a mountain path and heard them for a long time afterwards. You are so hungry that the pack animals that died in the night are now dinner, and you are glad of it. And here is the part that is hardest to hold in your head: you are one of the lucky ones, because you are still here. Hannibal came down into Italy with roughly twenty thousand foot soldiers and six thousand horsemen. He had set out with over a hundred thousand. Nearly three out of every four men who left Spain with him never reached Italy at all. Almost none of them died in a battle.',
        ],

        // ── Winning everything, and still losing ─────────────────────────
        [
            'type' => 'voyage', 'voyage' => 'hannibal-218', 'leg' => 5,
            'chapter' => 'The Trebia',
            'location' => 'The river Trebia',
            'year' => -218,
            'title' => 'Cold water, before breakfast',
            'date_label' => 'December, 218 BC',
            'story' => 'Hannibal picked a freezing December morning, sent light cavalry to provoke the Romans out of camp before they had eaten, and made them wade a river shoulder deep in icy water. Then he sprang an ambush he had hidden in a stream bed the night before. It was his first battle in Italy, and it set the pattern.',
            'images' => [
                'commons:Camille corot, veduta della campagna romana, 1825-28.jpg',
                'commons:Battle of Trebbia (M. Merian).png',
                "commons:Trajan's Column - NMR - panel 017 A.jpg",
            ],
            'prefer' => 'art',
            'script' => 'His first battle in Italy tells you everything about how his mind worked. December, bitterly cold. He fed his own men and had them oil themselves by the fires. Then he sent a handful of horsemen to insult the Roman camp until the Romans came out, hungry, straight out of bed, and had to wade a river that was chest deep and full of meltwater to reach him. By the time they climbed the far bank they were shaking too hard to hold a shield properly. And his brother was waiting in a hidden stream bed to hit them from behind. Hannibal did not usually beat Roman armies with better soldiers. He beat them by deciding where, when and in what state they would arrive.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'hannibal-218', 'leg' => 6,
            'chapter' => 'Ambush in the fog',
            'location' => 'Lake Trasimene',
            'year' => -217,
            'title' => 'The worst ambush in Roman history',
            'date_label' => '21 June, 217 BC',
            'story' => 'At Lake Trasimene, Hannibal hid his whole army in the hills along a narrow road between the water and the slopes, and waited for a morning mist. A Roman army of around twenty five thousand marched into the gap. Fighting lasted perhaps three hours. Some men drowned trying to escape into the lake in their armour.',
            'images' => [
                'commons:Camille corot, la cervara, campagna romana, 1830-31.jpg',
                'commons:Trasimene battlefield.JPG',
                'commons:Sylvestre Ducar decapite Flaminius (Trasimene).jpg',
            ],
            'prefer' => 'art',
            'script' => 'Six months later he did something even bolder. At Lake Trasimene there is a stretch of road with the water on one side and steep hills on the other, and Hannibal put his entire army into those hills overnight. In the morning a mist came off the lake. A Roman army of twenty five thousand marched into that gap in column, unable to see more than a few metres, and the hills came down on them. It was over in about three hours. Men who ran for the water drowned, because nobody could swim in armour. The Roman commander was killed. And in Rome, a magistrate climbed onto the speakers\' platform in the Forum and announced it in one sentence: we have been beaten in a great battle.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'hannibal-218', 'leg' => 7,
            'chapter' => 'Cannae',
            'location' => 'Cannae',
            'year' => -216,
            'title' => 'The second of August, 216 BC',
            'date_label' => '2 August, 216 BC',
            'story' => 'Rome raised the largest army it had ever put in one place, around eighty thousand men, and sent it to finish Hannibal. He had fewer than fifty thousand. He let his centre be pushed slowly backwards until the Roman mass had walked into a bag, then closed his cavalry behind them. Somewhere between fifty and seventy thousand Romans were killed in a single day.',
            // Zama's engraving used to sit here as well as on the Zama scene. Cannae has its own
            // painting — Trumbull's — and the Slodtz marble is Hannibal counting the rings taken
            // off the Roman dead of THIS battle, which is the number the script quotes.
            'images' => [
                'commons:John Trumbull - The Death of Paulus Aemilius at the Battle of Cannae - 1832.100 - Yale University Art Gallery.jpg',
                'commons:Traj col battle 4.jpg',
                'commons:Hannibal Slodtz Louvre MR2093.jpg',
            ],
            'prefer' => 'art',
            'script' => 'And then Cannae. Rome had had enough of clever ambushes, so it built the biggest army in its history, around eighty thousand men, and marched them out onto an open plain where there was nowhere to hide an ambush at all. Hannibal had fewer than fifty thousand. He put his weakest troops in the middle and his best on the wings, and he let that middle bend slowly backwards under Roman pressure, and the Romans pushed into it, and pushed, and thought they were winning. They were walking into a bag. When the sides closed and his cavalry came round behind, eighty thousand men were packed into a space too small to lift a sword. Somewhere between fifty and seventy thousand of them were killed in one afternoon. It is still, two thousand years later, one of the worst single days any army has ever had.',
        ],
        [
            'type' => 'gallery',
            'chapter' => 'One man in that field',
            'location' => 'Cannae',
            'year' => -216,
            'title' => 'What Cannae was actually like',
            'date_label' => '2 August, 216 BC',
            'story' => 'Now put yourself in the middle of it, in the shoes of an ordinary Roman legionary. You are probably a farmer. You own your own equipment because that is the rule, so the shield you are holding cost your family real money. You are somewhere in the centre of a block of men eighty thousand strong, and at first the day goes well: the enemy in front of you is giving way. You push forward. Everyone pushes forward. Then the pushing stops, and you cannot understand why, and the men behind you keep coming. The sun is high. Nobody near you can see anything except the back of the man in front. You hear shouting from behind you, which makes no sense, because behind you is your own army. Livy writes that the next morning the Romans found men who had dug holes in the ground and pressed their faces into them to suffocate themselves. Around one Roman soldier in five who marched out that morning walked away. This is what a number in a textbook is made of.',
            'images' => [
                'commons:Jean-Léon Gérôme - The Christian Martyrs\' Last Prayer - Walters 37113.jpg',
                'commons:Dying Gaul Musei Capitolini MC747.jpg',
                'commons:Grand Ludovisi Sarcophagus 04.jpg',
            ],
            'prefer' => 'art',
            'script' => 'Put yourself in that field, in the shoes of an ordinary Roman soldier. You are probably a farmer. You bought your own shield, because that is the rule, and it cost your family real money. At first the day goes well: the enemy in front of you is giving way, so you push forward, and everyone around you pushes forward. Then the pushing stops. You cannot see why, because all you can see is the back of the man in front of you, and the men behind you are still coming. Then you hear shouting from behind, which makes no sense, because behind you is your own army. Livy tells us that the next morning the Romans found men who had scraped holes in the earth and pushed their faces into them to stop themselves breathing. About one Roman in five who marched out that morning came home. That is what a number in a textbook is actually made of.',
        ],

        // ── Why Rome did not lose ────────────────────────────────────────
        [
            'type' => 'story',
            'chapter' => 'The city that would not surrender',
            'location' => 'Rome',
            'year' => -216,
            'image' => 'commons:Cicero Denounces Catiline in the Roman Senate by Cesare Maccari.png',
            'prefer' => 'art',
            'script' => 'By every rule of the ancient world, Rome should have surrendered now. Hannibal expected it. He waited, and he sent an offer, and Rome would not even let his messenger through the gates. Instead the Senate banned the word peace, forbade families from crying in public because it was bad for morale, and started raising new legions from teenagers, from debtors, and from eight thousand slaves who were bought with public money and promised their freedom. Then they refused to fight him. A commander called Fabius Maximus shadowed Hannibal around Italy for years, never accepting a battle, burning the crops ahead of him so there was nothing to eat. The Romans nicknamed Fabius the Delayer, and they meant it as an insult, and it won the war. Hannibal stayed in Italy for fifteen more years. He never took Rome. He simply could not make it admit that it had lost.',
        ],
        [
            'type' => 'story',
            'chapter' => 'Zama, and the end of the second war',
            'location' => 'Zama',
            'year' => -202,
            'image' => 'commons:The Battle Between Scipio and Hannibal at Zama MET DP874322.jpg',
            'prefer' => 'art',
            'script' => 'A young Roman called Scipio had survived Cannae as a teenager, and he had spent his life since studying the man who did it to him. Rather than chase Hannibal around Italy, Scipio took an army to Africa and threatened Carthage itself, and Carthage had to call Hannibal home. After thirty six years away, he came back to defend a city he barely knew. The two of them met at Zama in 202 BC. Polybius says they spoke before the battle. Scipio had learned Hannibal\'s trick of the bending centre and knew how to answer it. He also knew what to do about elephants: open lanes in the ranks and let them run harmlessly through. Hannibal lost. Carthage gave up its navy, its empire and its right to make war without Rome\'s permission.',
        ],

        // ── The end of Carthage ──────────────────────────────────────────
        [
            'type' => 'gallery',
            'chapter' => 'The end of Carthage',
            'location' => 'Carthage',
            'year' => -146,
            'title' => 'The third war, and a story that is not true',
            'date_label' => '149 to 146 BC',
            'story' => 'Fifty years later Carthage was no threat to anybody. It had paid its fine, kept its treaty and grown rich again on trade. That last part was the problem. A senator called Cato ended every speech he gave, on any subject at all, with the same line: and furthermore, I consider that Carthage must be destroyed. In 149 BC Rome found a pretext and did it. The city held out for three years. When it fell, the survivors were sold into slavery and the buildings were pulled down. Polybius was standing next to the Roman general, his friend Scipio Aemilianus, and wrote that the general looked at the burning city and wept, and said that one day the same thing would happen to Rome. You may have heard that the Romans ploughed salt into the fields so nothing would ever grow. That story is not in any ancient source. It was invented by historians in the nineteenth century, and it spread because it sounds exactly like something Rome would do. Be careful with facts that feel too fitting.',
            // Ends on Dido building the city, which opened the lesson — the same picture, now read
            // backwards. Everything before it is the wreck: Cole's burning capital, then the real
            // ruins and the real harbour, photographed.
            'images' => [
                'commons:Joseph Mallord William Turner - The Decline of the Carthaginian Empire - WGA23169.jpg',
                'commons:Cole Thomas The Course of Empire Destruction 1836.jpg',
                'commons:Antonine Baths at Carthage.jpg',
                'commons:PortsPuniquesSalamboTunis.jpg',
                'commons:Turner Dido Building Carthage.jpg',
            ],
            'prefer' => 'art',
            'script' => 'Fifty years later, Carthage was no danger to anyone. It had paid its fine, kept every term of the treaty, and grown rich again by trading. That last part was the problem. A senator called Cato finished every speech he made, on any subject whatsoever, with the same sentence: and furthermore, I consider that Carthage must be destroyed. In 149 BC Rome found an excuse. The city held out for three years. When it finally fell, the survivors were sold into slavery and the buildings were torn down. The historian Polybius stood next to the Roman general as it burned, and wrote that his friend wept, and said that one day this would happen to Rome too. One more thing. You may have been told that the Romans ploughed salt into the soil so nothing would grow there again. It is a wonderful detail and it appears in no ancient source at all. Historians made it up in the eighteen hundreds. It spread because it sounds exactly like what Rome would do. Be careful with the facts that fit too well.',
        ],
        [
            'type' => 'story',
            'chapter' => 'What the wars actually changed',
            'location' => 'Rome',
            'year' => -140,
            'image' => 'commons:Andrea Locatelli Veduta del Colosseo.jpg',
            'prefer' => 'art',
            'script' => 'Rome went into these wars as one Italian city among several. It came out owning Spain, North Africa, Sicily and the sea in between, and there was nobody left in the western Mediterranean who could say no to it. Everything you think of as Roman, the emperors, the roads across Europe, the Colosseum, the language that turned into Italian, Spanish, French and Portuguese, all of it stands on top of these hundred and eighteen years. And Hannibal? He ran. He advised other kings on how to fight Rome, and Rome hunted him from country to country for thirty years, until he was cornered in a house on the Black Sea coast around 183 BC and took poison rather than be handed over. He was about sixty four. He had beaten Rome more completely than anyone in history, and it had not been enough.',
        ],

        // ── Assessment ───────────────────────────────────────────────────
        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'What did you learn?',
            // One question per beat, in the order the beats were told, so a class re-walks the story
            // rather than sampling it. Each answer is stated in the scene named above the question
            // and in no later one.
            //
            // Every option in a question is written to roughly the SAME LENGTH. The previous set
            // failed this in five questions out of six: the correct answer was the longest option by
            // 2 to 10 words, and on the rendered card it was the only one that wrapped to a second
            // line. Since `quiz_shuffle` is per_player the position moves, so length was the one
            // stable cue left, and picking the longest row scored 5/6 without reading the question.
            // Distractors are real things from the period or genuine misconceptions — never filler,
            // because an obviously silly option removes itself and makes a 4-choice question a
            // 2-choice one.
            //
            // `c` is spread across all four positions rather than left at 0. The composer sets
            // per_player shuffle so position is hidden today, but the wizard's quiz editor can turn
            // shuffling off for a class, and a spec with every answer at index 0 would then hand
            // that class ten questions whose answer is always A.
            'questions' => [
                [
                    // scene 3 — The first war: Rome learns to swim
                    'q' => 'Rome had no real navy in 264 BC. How did it win the war at sea?',
                    'o' => [
                        'It blockaded Carthage until the fleet surrendered',
                        'It hired Greek crews from the cities of Sicily',
                        'It copied a wrecked warship and boarded the enemy',
                        'It waited for storms to break the Punic fleet',
                    ],
                    'c' => 2,
                    'e' => 'They copied a Carthaginian ship that had run aground, then dropped a spiked bridge onto enemy decks so every sea battle became a land battle. Storms did sink hundreds of ships, but Rome\'s, and it simply built more.',
                ],
                [
                    // scene 4 — A promise made by a nine year old
                    'q' => 'Polybius says Hamilcar made his nine year old son swear what, at an altar?',
                    'o' => [
                        'That he would never be a friend to Rome',
                        'That he would win Sicily back for Carthage',
                        'That he would avenge his father in battle',
                        'That he would rule Carthage after him',
                    ],
                    'c' => 0,
                    'e' => 'Never a friend to Rome. We cannot check it, because Hannibal told the story himself many years later and had good reasons to tell it. But everything he did afterwards fits it exactly.',
                ],
                [
                    // scene 5 — Where it happened (the map)
                    'q' => 'Why did Hannibal march to Italy instead of sailing there?',
                    'o' => [
                        'His elephants were too heavy to carry by ship',
                        'Rome controlled the sea and could sink his ships',
                        'The march let him recruit tribes along the way',
                        'Carthage had sold its warships after the first war',
                    ],
                    'c' => 1,
                    'e' => 'After the first war Rome owned that water, so any ship he put on it could be sunk. Walking was the only route to Italy that Rome did not control.',
                ],
                [
                    // scene 8 — Elephants on the river
                    'q' => 'How did Hannibal get his elephants across the Rhône?',
                    'o' => [
                        'He swam them across roped behind rowing boats',
                        'On flat barges borrowed from the Gauls upstream',
                        'He marched them to a shallow ford further north',
                        'On rafts covered with earth to look like ground',
                    ],
                    'c' => 3,
                    'e' => 'Rafts piled with earth and turf until they looked like solid ground, so the animals walked on believing it. When the ropes were cut some panicked into the river and crossed along the bottom, with only their trunks above the water.',
                ],
                [
                    // scenes 9-10 — The Alps, and What it cost
                    'q' => 'Hannibal left Spain with over a hundred thousand men. How many reached Italy?',
                    'o' => [
                        'About a tenth of them',
                        'About a quarter of them',
                        'About half of them',
                        'Nearly all of them',
                    ],
                    'c' => 1,
                    'e' => 'Around twenty six thousand out of more than a hundred thousand. Almost none of the missing died in a battle: the mountains, the hunger and the men who walked home did it.',
                ],
                [
                    // scenes 11-12 — The Trebia, and Ambush in the fog
                    'q' => 'At the Trebia and at Lake Trasimene, how did Hannibal beat larger Roman armies?',
                    'o' => [
                        'He bribed the Roman commanders to pull back',
                        'He had far better armour and heavier weapons',
                        'He chose when and where they would fight',
                        'He fought only at night, while the Romans slept',
                    ],
                    'c' => 2,
                    'e' => 'Cold water before breakfast at the Trebia, a lake mist at Trasimene. He did not beat Romans with better soldiers. He decided the time, the place, and what state they would arrive in.',
                ],
                [
                    // scene 13 — Cannae
                    'q' => 'At Cannae, what did Hannibal do with the centre of his own line?',
                    'o' => [
                        'He let it bend back until the Romans crowded in',
                        'He pulled it out and attacked from the flank',
                        'He packed it with his strongest African troops',
                        'He hid his cavalry behind it until first light',
                    ],
                    'c' => 0,
                    'e' => 'His weakest troops were in the middle on purpose. The centre gave way slowly, the Romans pushed into the pocket thinking they were winning, and then the wings and the cavalry closed behind them.',
                ],
                [
                    // scene 15 — The city that would not surrender
                    'q' => 'After Cannae, Hannibal offered terms. What did Rome do?',
                    'o' => [
                        'It paid him to take his army out of Italy',
                        'It agreed, and then broke the treaty a year later',
                        'It asked the Greek kings to negotiate for it',
                        'It refused to let his messenger through the gates',
                    ],
                    'c' => 3,
                    'e' => 'It banned the word peace, raised new legions from teenagers, debtors and eight thousand slaves, and then refused to fight him at all. Fabius shadowed him for years and never gave battle.',
                ],
                [
                    // scene 16 — Zama, and the end of the second war
                    'q' => 'How did Scipio deal with Hannibal\'s elephants at Zama?',
                    'o' => [
                        'He drove them back with trumpets and drums',
                        'He opened lanes and let them charge through',
                        'He shot them with catapults before they charged',
                        'He set the ground alight in front of them',
                    ],
                    'c' => 1,
                    'e' => 'He left gaps in his ranks and let them charge straight through. Fire, missiles and noise were all real tactics against elephants; Scipio had survived Cannae as a teenager and had spent his life studying the man who did it to him.',
                ],
                [
                    // scene 17 — The end of Carthage
                    'q' => 'Which ancient source records Rome ploughing salt into Carthage\'s fields?',
                    'o' => [
                        'Cato, in his speeches to the Roman Senate',
                        'Polybius, who stood there watching it burn',
                        'None of them, the story was invented much later',
                        'Livy, in his history of the wars with Carthage',
                    ],
                    'c' => 2,
                    'e' => 'No ancient source mentions it at all. Historians invented it in the eighteen hundreds and it spread because it sounds exactly like something Rome would do. Be careful with the facts that fit too well.',
                ],
            ],
        ],
    ],
];
