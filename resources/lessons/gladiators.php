<?php

declare(strict_types=1);

/**
 * Gladiators — the arena from the fighter's side of the sand. Roughly ages 11–14.
 *
 * This is the lesson that most needs to resist Hollywood, so it leans hard on physical evidence:
 * the Ephesus gladiator cemetery, the Zliten and Borghese mosaics, Martial's account of Verus and
 * Priscus. Where a famous image is unreliable (Gérôme's thumbs-down, which invented how we picture
 * the gesture) the lesson says so and shows the painting anyway, because learning to read a picture
 * sceptically is part of the point.
 *
 * Violence is handled honestly but without relish: no lingering, and the recurring question is
 * what it was like to be the person on the sand rather than the person in the seats.
 */
return [
    'key' => 'Gladiators',
    'title' => 'Gladiators',
    'subject' => 'history',
    'grade_level' => '7',
    'map_style' => 'antique',

    'scenes' => [

        [
            'type' => 'quiz',
            'when' => 'pre',
            'chapter' => 'What do you already know?',
            'questions' => [
                [
                    'q' => 'Were all gladiators slaves?',
                    'o' => ['Yes, always', 'No, some were free men who volunteered', 'They were all prisoners of war', 'They were all Roman soldiers'],
                    'c' => 1,
                    'e' => 'Most were enslaved or condemned, but a real number were free men who signed up for money and fame.',
                ],
                [
                    'q' => 'Did most gladiator fights end in someone\'s death?',
                    'o' => ['Yes, nearly every fight', 'No, a death was the exception rather than the rule', 'Only on holidays', 'Nobody ever died'],
                    'c' => 1,
                    'e' => 'Trained fighters were expensive to buy and to train. Killing them off every show made no financial sense.',
                ],
                [
                    'q' => 'Where did gladiators live and train?',
                    'o' => ['In the Colosseum itself', 'In a barracks school called a ludus', 'At home with their families', 'In the army camp'],
                    'c' => 1,
                    'e' => 'In a ludus: a training school with barracks, a practice arena, doctors and a strict diet.',
                ],
                [
                    'q' => 'Which famous gladiator led a slave rebellion against Rome?',
                    'o' => ['Spartacus', 'Commodus', 'Verus', 'Crixus'],
                    'c' => 0,
                    'e' => 'Spartacus escaped from a gladiator school at Capua in 73 BC and held out against Roman armies for two years.',
                ],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'The most famous painting of a thing that may never have happened',
            'location' => 'Rome',
            'year' => 100,
            'image' => 'commons:Jean-Leon Gerome Pollice Verso.jpg',
            'prefer' => 'art',
            'script' => 'This is probably the picture in your head when someone says the word gladiator, even if you have never seen it before. A fighter stands over a beaten opponent and looks up at the crowd, and the crowd is jabbing their thumbs downwards. It was painted in 1872 by a Frenchman called Jean-Léon Gérôme, and it is magnificent, and it is also the reason the whole world believes thumbs down means kill him. Here is the problem. The Romans did use a thumb gesture, and they called it pollice verso, the turned thumb. But no Roman writer ever explains which way it pointed or what it meant, and some historians think the gesture for death was a thumb pushed towards the throat, or that showing the thumb at all meant draw your sword. Gérôme guessed. His guess was so convincing that it became what everybody knows. Keep that in mind for the rest of this lesson: a lot of what we picture about gladiators was painted, filmed, or written by people who were nowhere near.',
        ],

        [
            'type' => 'map',
            'chapter' => 'Where the fighters came from',
            'location' => 'The Roman Empire',
            'year' => 100,
            'projection' => 'mercator',
            'script' => 'Before we go into the arena, look at where the men on the sand actually came from. Rome is here in the middle. But the fighting styles are named after Rome\'s enemies: the thraex is named for Thrace, up here in the Balkans. Others were named for the Gauls in the north and for Samnites in southern Italy. Numidians and other North Africans came from down here. When a Roman watched a Thracian-style fighter with his curved sword and little square shield, he was watching a memory of a war his grandfather fought. The arena was not only entertainment. It was the empire showing the people of Rome the men it had defeated, over and over, in a place where the ending was guaranteed.',
            'labels' => [
                ['Rome', 12.50, 41.90],
                ['Gaul', 3.00, 46.50],
                ['Thrace', 26.00, 42.00],
                ['Numidia', 6.00, 36.00],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'How a person became a gladiator',
            'location' => 'Capua',
            'year' => 80,
            'image' => 'commons:Gladiators, fragment, Roman, 300-400 AD, marble and limestone mosaic - Galleria Borghese - Rome, Italy - DSC04925.jpg',
            'prefer' => 'art',
            'script' => 'There were four ways to end up on the sand. Most fighters were enslaved people, bought for the purpose. Some were prisoners of war, which is why so many fighting styles were named after defeated peoples. Some were criminals sentenced to the arena, and they were at the bottom of everything. And a surprising number, perhaps one in five, were free men who volunteered. A volunteer swore an oath that he agreed to be burned, bound, beaten and killed by the sword, and in that moment he gave up almost every right a Roman had. Why would anyone do that? For the same reasons people take dangerous jobs now. Debt. A signing fee paid up front. Food every day, which not everyone had. And the small chance of becoming famous in a city where a poor free man was nobody at all.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'Life in the school',
            'location' => 'The ludus',
            'year' => 80,
            'title' => 'Barley men',
            'date_label' => 'First century AD',
            'story' => 'A gladiator school was a barracks, a prison and a training academy at once. Fighters were locked in at night, trained daily with weapons deliberately made heavier than the real thing, and fed a starchy vegetarian diet of barley and beans. Other Romans mocked them as hordearii, the barley men. In 1993 a cemetery of about sixty eight gladiators was excavated at Ephesus, and their bones settled several arguments at once. Chemical analysis of the bones matched that grain-heavy diet almost exactly. The skeletons showed heavy, expert muscle attachment. Many carried old wounds that had healed cleanly, which means they were fought, injured, treated properly and sent back, not discarded. And the fatal injuries were startlingly precise: single clean strikes, often to the head, sometimes with a squared instrument that matches a described killing blow delivered after the fight. These were valuable, carefully maintained professional athletes who were also, at the same time, disposable.',
            'images' => [
                'commons:Gladiators from the Zliten mosaic 3.JPG',
                'commons:Gladiators, fragment, Roman, 300-400 AD, marble and limestone mosaic - Galleria Borghese - Rome, Italy - DSC04925.jpg',
                'commons:Ave Caesar Morituri te Salutant (Gérôme) 01.jpg',
            ],
            'prefer' => 'art',
            'script' => 'A gladiator school was a barracks and a prison and a sports academy at the same time. The men were locked in at night. They trained every day with wooden and iron weapons made deliberately heavier than the real ones. They ate a starchy vegetarian diet of barley and beans, so much of it that other Romans mocked them as hordearii, the barley men. And then in 1993 archaeologists excavated a cemetery of about sixty eight gladiators at Ephesus, and the bones answered questions the books could not. The chemistry of the bones matched that barley diet. The muscle attachments were huge and expert. Many of the men carried old wounds that had healed cleanly, which means they were injured, properly treated by doctors, and sent back to fight again. And the wounds that killed them were extraordinarily precise: single clean strikes. These were valuable professional athletes who were looked after carefully, and who were also completely disposable. Both things were true at once.',
        ],

        [
            'type' => 'story',
            'chapter' => 'In the shoes of a fighter',
            'location' => 'The Colosseum',
            'year' => 80,
            'image' => 'commons:Ave Caesar Morituri te Salutant (Gérôme) 01.jpg',
            'prefer' => 'art',
            'script' => 'Now stand where he stood. You are in a corridor under the arena floor in the dark, and you have been here since before dawn. You can hear the crowd through the stone above you, which sounds less like people and more like weather. You have eaten nothing, because a wound to a full stomach is far more likely to kill you. Your armour is heavy and it does not protect the places you would expect: your chest and stomach are bare, because the crowd has paid to see a body, and being harder to kill is not the point. Your helmet is a bronze bucket with two small eye holes, so you can hear the noise but you cannot tell where it is coming from. There is a doctor in the corridor behind you, because you are worth too much to waste. You have fought maybe twice this year, and you may fight for another ten years, and if you last that long you may be given a wooden sword called a rudis, which means you are free. Around one fight in ten ends with somebody dead. Those are decent odds, right up until they are not.',
        ],

        [
            'type' => 'story',
            'chapter' => 'Two men who both won',
            'location' => 'The Colosseum',
            'year' => 80,
            'image' => 'commons:Gladiators from the Zliten mosaic 3.JPG',
            'prefer' => 'art',
            'script' => 'We know the name of almost no gladiator. Here are two. At the opening games of the Colosseum in the year 80, two men called Verus and Priscus fought each other, and the poet Martial was in the crowd and wrote it down. They were evenly matched, and they fought for a long time, and neither could win. The crowd began shouting for both of them to be released. Under the old rules that was impossible, because a fight had to have a loser. So the emperor Titus did something new. He ruled that both men had won, and gave them both the wooden sword and their freedom, and they walked out of the arena together. It is the only detailed description of a single gladiator fight that survives from anybody who was actually there. Out of thousands upon thousands of fights across five hundred years, we have one, and by pure chance it is the one where nobody died.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'The parts that are not true',
            'location' => 'Rome',
            'year' => 200,
            'title' => 'Four things everybody knows that are wrong',
            'date_label' => 'Then and now',
            'story' => 'First: gladiators did not usually shout "we who are about to die salute you". The line is recorded once, at a staged naval battle under the emperor Claudius, by men who were condemned criminals, not gladiators. Gérôme painted it anyway, and now it is in every film. Second: the arena was not mainly where Christians were killed. Romans certainly executed Christians, and executions did happen at the games, but the specific tradition that huge numbers died in the Colosseum grew up in the Middle Ages and the evidence for it is very thin. Third: thumbs down. Gérôme guessed, as we have seen. Fourth: gladiators were not all men. There were women fighters, rare enough to be a novelty and real enough that the emperor Septimius Severus had to ban them by law around the year 200, and a marble relief from Halicarnassus shows two of them, named Amazon and Achillia, fighting to an honourable draw.',
            'images' => [
                'commons:Jean-Léon Gérôme - The Christian Martyrs\' Last Prayer - Walters 37113.jpg',
                'commons:Jean-Leon Gerome Pollice Verso.jpg',
                'commons:Gladiators, fragment, Roman, 300-400 AD, marble and limestone mosaic - Galleria Borghese - Rome, Italy - DSC04925.jpg',
            ],
            'prefer' => 'art',
            'script' => 'Four things almost everybody knows, which are not quite right. One: gladiators did not routinely say we who are about to die salute you. The line is recorded once, at a staged naval battle, spoken by condemned criminals rather than gladiators. Two: the Colosseum was not mainly a place where Christians were killed. Romans did execute Christians, and executions did happen at the games, but the idea that huge numbers died specifically in this building grew up in the Middle Ages, and the evidence is thin. Three: the thumbs down gesture, which a French painter invented in 1872. And four: not all gladiators were men. Women fought too, rarely enough to be a novelty and often enough that an emperor had to ban it by law around the year 200. A carved relief shows two of them, Amazon and Achillia, fighting to an honourable draw.',
        ],

        [
            'type' => 'story',
            'chapter' => 'Spartacus',
            'location' => 'Capua',
            'year' => -73,
            'image' => 'commons:Gladiators, fragment, Roman, 300-400 AD, marble and limestone mosaic - Galleria Borghese - Rome, Italy - DSC04925.jpg',
            'prefer' => 'art',
            'script' => 'In 73 BC, about seventy men broke out of a gladiator school in Capua using kitchen knives and cooking spits. Their leader was a Thracian called Spartacus. What should have been a local police problem turned into two years of war, because enslaved people across southern Italy left the farms and joined them until the army numbered in the tens of thousands, and because they beat Roman force after Roman force. In the end Rome sent eight legions under Crassus. Spartacus was killed somewhere in the final battle and his body was never identified. Six thousand of his followers were crucified along the road from Capua to Rome, one every thirty five metres or so, for a hundred and ninety kilometres, and left there. Rome understood exactly what the arena was for, and exactly what happened when the men in it stopped agreeing to be in it.',
        ],

        [
            'type' => 'story',
            'chapter' => 'How it ended',
            'location' => 'Rome',
            'year' => 404,
            'image' => 'commons:Franz Ludwig Catel - Parti af Colosseum i måneskin.jpg',
            'prefer' => 'art',
            'script' => 'The games did not end in a single dramatic moment. They faded across the fourth and fifth centuries as the empire became Christian, as the money ran out, and as it became harder to justify the cost. The traditional story is that in the year 404 a monk called Telemachus walked into the arena to stop a fight and was killed by the crowd, and that the emperor Honorius banned gladiatorial games in response. Whether that is exactly what happened is uncertain, but gladiator combat did stop around then. Animal hunts carried on for another century or so. And the building emptied out, and became in turn a fortress, a church, a quarry that supplied stone for Renaissance palaces, and finally a ruin full of wild plants, which is where the painters found it.',
        ],

        [
            'type' => 'story',
            'chapter' => 'Why this still matters',
            'location' => 'Rome',
            'year' => 2026,
            'image' => 'commons:Ippolito Caffi, Interior of the Colosseum, NGA 143645.jpg',
            'prefer' => 'art',
            'script' => 'It is comfortable to decide the Romans were simply monsters, and then stop thinking. It is more useful, and more uncomfortable, to notice what they had built. A stadium. Ticketed seats. Star athletes with fan graffiti and merchandise, whose names children scratched on walls. Betting. A crowd that could decide a man\'s fate by shouting together, and went home afterwards and had dinner. The Romans were not a different species. They were people who had been taught, from childhood, that certain other people did not fully count, and who then behaved exactly as you would expect. That is the part worth carrying out of this lesson, because it is the part that did not stay in the past.',
        ],

        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'What did you learn?',
            'questions' => [
                [
                    'q' => 'Why did most gladiator fights not end in death?',
                    'o' => [
                        'The emperor forbade killing',
                        'Trained fighters were expensive to buy and train, so killing them off made no sense',
                        'Their weapons were blunt',
                        'The crowd was not allowed to ask for a death',
                    ],
                    'c' => 1,
                    'e' => 'A trained gladiator represented a large investment. Roughly one fight in ten ended with a death.',
                ],
                [
                    'q' => 'What did the Ephesus gladiator cemetery show about how fighters were treated?',
                    'o' => [
                        'They were starved and never given medical care',
                        'They ate a barley-heavy diet and had wounds that had been treated and healed',
                        'They were all over sixty when they died',
                        'They were not really athletes',
                    ],
                    'c' => 1,
                    'e' => 'Bone chemistry matched the barley diet, and many skeletons showed old wounds that had healed cleanly, meaning real medical care.',
                ],
                [
                    'q' => 'Where does our image of "thumbs down" come from?',
                    'o' => [
                        'A Roman law describing the gesture',
                        'A painting by Jean-Léon Gérôme in 1872',
                        'A mosaic from Zliten',
                        'The poet Martial',
                    ],
                    'c' => 1,
                    'e' => 'No Roman source explains which way the thumb pointed. Gérôme guessed, and his guess became what everybody pictures.',
                ],
                [
                    'q' => 'What was unusual about the fight between Verus and Priscus?',
                    'o' => [
                        'It lasted three days',
                        'Both men were declared winners and both were freed',
                        'Neither man was a real gladiator',
                        'It was fought with no weapons',
                    ],
                    'c' => 1,
                    'e' => 'Titus ruled that both had won and gave both the wooden sword of freedom. Martial, who was there, wrote it down.',
                ],
                [
                    'q' => 'Why were fighting styles named after peoples like the Thracians and Gauls?',
                    'o' => [
                        'Those peoples invented the sport',
                        'The arena replayed Rome\'s victories over enemies it had defeated',
                        'The names were chosen at random',
                        'Only foreigners were allowed to fight',
                    ],
                    'c' => 1,
                    'e' => 'The styles echoed defeated enemies, so the games doubled as a reminder of Roman conquest with a guaranteed ending.',
                ],
                [
                    'q' => 'What happened after the revolt of Spartacus was defeated?',
                    'o' => [
                        'The survivors were freed',
                        'Around six thousand followers were crucified along the road from Capua to Rome',
                        'Gladiator games were banned',
                        'Spartacus was made a Roman citizen',
                    ],
                    'c' => 1,
                    'e' => 'Crassus had roughly six thousand crucified along the Appian Way and left there as a warning.',
                ],
            ],
        ],
    ],
];
