<?php

declare(strict_types=1);

/** William of Orange and the Dutch Revolt — how a rebellion became a country. */
return [
    'key' => 'William of Orange and the Dutch Revolt',
    'title' => 'William of Orange and the Dutch Revolt',
    'subject' => 'history',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [
        [
            'type' => 'quiz', 'when' => 'pre', 'chapter' => 'What do you already know?',
            'questions' => [
                ['q' => 'Who ruled the Low Countries at the start of the revolt?', 'o' => ['King Philip II of Spain', 'The French king', 'The Pope', 'The Dutch parliament'], 'c' => 0, 'e' => 'Philip II inherited the Low Countries and ruled them from Spain.'],
                ['q' => 'What is William of Orange\'s nickname?', 'o' => ['William the Bold', 'William the Silent', 'William the Great', 'William the Younger'], 'c' => 1, 'e' => 'Willem de Zwijger — the Silent — supposedly for keeping his opinions to himself.'],
                ['q' => 'How long did the Dutch war of independence last?', 'o' => ['8 years', '30 years', '80 years', '150 years'], 'c' => 2, 'e' => 'The Eighty Years\' War, from 1568 to 1648.'],
                ['q' => 'What is the Dutch national anthem, the Wilhelmus, about?', 'o' => ['The sea', 'William of Orange', 'Amsterdam', 'The royal family today'], 'c' => 1, 'e' => 'It is written as if William himself is speaking — the oldest national anthem in the world.'],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'A nobleman with a problem', 'location' => 'Brussels', 'year' => 1559,
            'image' => 'Willem van Oranje portret', 'prefer' => 'art',
            'script' => 'William of Nassau inherited the principality of Orange in southern France when he was eleven, and with it one of the great fortunes of Europe. He was raised at the court of Emperor Charles the Fifth, he was Catholic, he was enormously rich, and he was a loyal servant of the crown. Nothing about the young William suggests a rebel. But when Charles handed the Low Countries to his son Philip the Second, a Spanish king who had never lived there, things began to go wrong.',
        ],
        [
            'type' => 'story', 'chapter' => 'Taxes, bishops and heretics', 'location' => 'The Low Countries', 'year' => 1565,
            'image' => 'Filips II van Spanje portret', 'prefer' => 'art',
            'script' => 'Philip governed his Dutch provinces from Madrid, and he wanted three things: higher taxes to pay for his wars, more bishops to tighten control of the church, and the ruthless suppression of Protestants. The Low Countries were the richest, most urban, most independent-minded part of his empire, with old privileges the towns guarded jealously. When Philip\'s inquisition began burning Protestants, William — still Catholic himself — said something remarkable for the time: he could not approve of rulers trying to control the conscience of their subjects.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'The year of the images', 'location' => 'The Low Countries', 'year' => 1566,
            'title' => 'The Beeldenstorm', 'date_label' => 'August 1566',
            'story' => 'In the hot summer of 1566, after years of tension and a bad harvest, something snapped. Crowds moved through churches across the Low Countries smashing statues, altarpieces and stained glass — the Beeldenstorm, the iconoclastic fury. To Protestants, religious images were idols forbidden by the Bible. To Catholics, this was sacrilege on a shocking scale. Philip\'s response was to send the Duke of Alba with an army and a special tribunal that people nicknamed the Council of Blood. Thousands were tried; over a thousand were executed. Alba meant to end the trouble. He started a war.',
            'images' => ['Beeldenstorm 1566', 'Hertog van Alva portret', 'kerk beeldenstorm gravure'],
            'prefer' => 'art',
            'script' => 'In the hot summer of 1566, crowds moved through churches across the Low Countries smashing statues, altarpieces and stained glass. This was the Beeldenstorm, the iconoclastic fury. To Protestants, religious images were idols forbidden by the Bible; to Catholics this was shocking sacrilege. Philip\'s answer was to send the Duke of Alba with an army and a tribunal people nicknamed the Council of Blood. Thousands were tried, over a thousand executed. Alba meant to end the trouble. He started a war.',
        ],
        [
            'type' => '3d', 'chapter' => 'What a soldier wore', 'location' => 'The battlefield', 'year' => 1570,
            'sketchfab' => 'https://sketchfab.com/3d-models/16th-century-german-munition-half-armour-d532733687b54339bf598c93a2efbd6a',
            'script' => 'This is munition half armour — mass produced, cheap by the standards of the day, and issued to ordinary infantry in exactly this period. Turn it around. Breastplate and backplate, tassets hanging over the thighs, shoulder plates and a gorget at the throat. It protects the parts that a pike or a sword would reach, and leaves the rest, because armour is heavy and men have to march. Tens of thousands of men on both sides of this war wore something very like it.',
        ],
        [
            'type' => 'story', 'chapter' => 'The Sea Beggars take Den Briel', 'location' => 'Brielle', 'year' => 1572,
            'image' => 'inname Den Briel 1572 watergeuzen', 'prefer' => 'art',
            'script' => 'The revolt nearly died in its first years. William invaded twice and was beaten twice. Then, on the first of April 1572, a scruffy fleet of exiled rebels and privateers called the Watergeuzen — the Sea Beggars — captured the little port of Den Briel almost by accident, having been thrown out of English harbours. It was a small town. But town after town in Holland and Zeeland now declared for William, and suddenly the rebels held real territory. Dutch children still learn the rhyme: op 1 april verloor Alva zijn bril — on the first of April, Alba lost his spectacles.',
        ],
        [
            'type' => 'map', 'chapter' => 'A war of sieges', 'location' => 'The Netherlands', 'year' => 1574, 'qid' => 'Q55',
            'script' => 'This war was not fought in great open battles. It was fought town by town, siege by siege, in a landscape of rivers and dykes. Look at where the fighting concentrated. Leiden held out for months in 1574 until the rebels cut the dykes and flooded the countryside, sailing a relief fleet across drowned farmland to reach the starving city. Haarlem was not so lucky and its garrison was massacred. And in Delft stands the Prinsenhof, where the war would take its most personal turn.',
            'labels' => [
                ['Brielle — captured 1572', 4.1614, 51.8994],
                ['Leiden — relieved by flood, 1574', 4.4970, 52.1601],
                ['Haarlem — fell 1573', 4.6462, 52.3874],
                ['Delft — the Prinsenhof', 4.3571, 52.0116],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'Water as a weapon', 'location' => 'Leiden', 'year' => 1574,
            'image' => 'Ontzet van Leiden 1574', 'prefer' => 'art',
            'script' => 'The Dutch had one weapon nobody else had: their own land. Much of it sat below sea level, kept dry by dykes. Cut those dykes, and the countryside becomes a shallow sea that Spanish infantry cannot cross but flat-bottomed rebel boats can. It meant drowning your own farms and ruining your own harvest, and communities argued bitterly about it. At Leiden they did it anyway. The city was relieved on the third of October 1574, and Leiden still celebrates that date every year with herring and white bread.',
        ],
        [
            'type' => 'story', 'chapter' => 'Murder at the Prinsenhof', 'location' => 'Delft', 'year' => 1584,
            'image' => 'Prinsenhof Delft moord Willem van Oranje', 'prefer' => 'art',
            'script' => 'Philip put a price on William\'s head: a huge reward and a title of nobility for anyone who killed him. On the tenth of July 1584, a Frenchman named Balthasar Gerards, who had spent years working his way into William\'s household, shot him on the stairs of the Prinsenhof in Delft. William was fifty one. The bullet holes are still in the wall today, and you can go and look at them. He is remembered as the Vader des Vaderlands, the father of the fatherland, and he never lived to see the country he is credited with founding.',
        ],
        [
            'type' => 'video', 'chapter' => 'The story in two minutes', 'location' => 'The Netherlands',
            'youtube' => 'https://www.youtube.com/watch?v=JDncoRhhRaI', 'controls' => true, 'fit' => 'fit',
            'script' => 'This is the official Canon van Nederland clip about William of Orange. Watch it, and notice which parts of the story it chooses to tell.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'A republic by accident', 'location' => 'The Dutch Republic', 'year' => 1648,
            'title' => 'What the war produced', 'date_label' => '1588 – 1648',
            'story' => 'The rebels never planned to invent a new kind of country. They kept looking for an acceptable prince to rule them, and kept failing to find one, so in 1588 they simply carried on without one: the Republic of the Seven United Netherlands. Power sat with the towns and the provinces, and with an assembly called the States-General. It was messy, argumentative and surprisingly tolerant, because a state built by people fleeing religious persecution had a practical interest in leaving beliefs alone. Jewish families, French Huguenots and English dissenters all came. Spain finally recognised Dutch independence in 1648, at the Peace of Munster, eighty years after the first shots.',
            'images' => ['Vrede van Munster 1648 Ter Borch', 'Staten-Generaal Den Haag 17e eeuw', 'Republiek der Zeven Verenigde Nederlanden kaart'],
            'prefer' => 'art',
            'script' => 'The rebels never planned to invent a new kind of country. They kept looking for an acceptable prince, kept failing to find one, and in 1588 simply carried on without one: the Republic of the Seven United Netherlands. Power sat with the towns and the provinces. It was messy, argumentative and surprisingly tolerant, because a state built by people fleeing persecution had a practical interest in leaving beliefs alone. Spain finally recognised Dutch independence in 1648, eighty years after the first shots.',
        ],
        [
            'type' => 'story', 'chapter' => 'The oldest anthem in the world', 'location' => 'The Netherlands', 'year' => 1572,
            'image' => 'Wilhelmus tekst handschrift', 'prefer' => 'art',
            'script' => 'The Wilhelmus was written during the revolt, probably in the early 1570s, which makes it the oldest national anthem in the world in terms of its melody and words. It is strange in a way most anthems are not: it is sung in the first person, as if William himself is speaking, and in the first verse he declares that he has always honoured the King of Spain. That is not a mistake. William insisted to the end that he was not rebelling against a rightful king, only against bad government. The Netherlands still sings his words at football matches.',
        ],
        [
            'type' => 'quiz', 'when' => 'post', 'chapter' => 'What did you learn?',
            'questions' => [
                ['q' => 'Why did the Low Countries turn against Philip II?', 'o' => ['Higher taxes, new bishops and persecution of Protestants', 'He banned trade with England', 'He moved the capital to Amsterdam', 'He refused to build dykes'], 'c' => 0, 'e' => 'Taxes, church control and the inquisition together broke the towns\' loyalty.'],
                ['q' => 'What was the Beeldenstorm?', 'o' => ['A storm that flooded Zeeland', 'The smashing of religious images in churches in 1566', 'A famous painting', 'A siege of Antwerp'], 'c' => 1, 'e' => 'The iconoclastic fury of August 1566, which triggered Alba\'s crackdown.'],
                ['q' => 'What happened on 1 April 1572?', 'o' => ['William was killed', 'The Sea Beggars captured Den Briel', 'The war ended', 'Leiden was relieved'], 'c' => 1, 'e' => 'The capture of Den Briel gave the revolt its first real territory.'],
                ['q' => 'How was the siege of Leiden broken in 1574?', 'o' => ['A cavalry charge', 'The dykes were cut and a fleet sailed over flooded land', 'The Spanish ran out of gunpowder', 'The Pope intervened'], 'c' => 1, 'e' => 'The Dutch flooded their own farmland so relief boats could reach the city.'],
                ['q' => 'How did William of Orange die?', 'o' => ['In battle', 'Assassinated at the Prinsenhof in Delft in 1584', 'Of plague', 'He drowned'], 'c' => 1, 'e' => 'Balthasar Gerards shot him after Philip II offered a reward for his death.'],
                ['q' => 'What kind of state emerged from the revolt?', 'o' => ['A kingdom under a French prince', 'A republic run by towns and provinces', 'A Spanish province', 'An empire with an emperor'], 'c' => 1, 'e' => 'After failing to find an acceptable prince, the provinces governed themselves as a republic.'],
            ],
        ],
    ],
];
