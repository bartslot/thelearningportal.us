<?php

declare(strict_types=1);

/**
 * The Silk Road — Marco Polo's journey as the thread. Roughly ages 11–14.
 *
 * Uses the marco-polo-1271 voyage so the class watches the distance accumulate leg by leg; a
 * seventeen-year-old walking to China means nothing until you see how far it is. The lesson is
 * careful on two points that textbooks usually flatten: the Silk Road is a modern name for
 * something that was never one road, and Marco Polo's book is evidence that has to be weighed,
 * not a witness statement to be trusted.
 *
 * Every image is a PINNED file. Free-text searches for these subjects return modern travel photos.
 */
return [
    'key' => 'The Silk Road',
    'title' => 'The Silk Road',
    'subject' => 'history',
    'language' => 'en',
    'grade_level' => '7',
    'map_style' => 'satellite',

    'scenes' => [

        [
            'type' => 'quiz',
            'when' => 'pre',
            'chapter' => 'What do you already know?',
            'questions' => [
                [
                    'q' => 'The Silk Road connected which two ends of the world?',
                    'o' => ['Africa and Australia', 'Europe and East Asia', 'Spain and Brazil', 'India and Japan'],
                    'c' => 1,
                    'e' => 'It linked the Mediterranean world with China, across roughly eight thousand kilometres of Asia.',
                ],
                [
                    'q' => 'Which European traveller wrote the most famous account of the journey?',
                    'o' => ['Christopher Columbus', 'Marco Polo', 'Ibn Battuta', 'Vasco da Gama'],
                    'c' => 1,
                    'e' => 'Marco Polo, a Venetian who left home in 1271 and did not return for twenty four years.',
                ],
                [
                    'q' => 'Besides silk, what else travelled along these routes?',
                    'o' => ['Only silk and spices', 'Goods, religions, inventions and diseases', 'Only gold', 'Nothing else'],
                    'c' => 1,
                    'e' => 'Paper, gunpowder, Buddhism, Islam, music, mathematics and the Black Death all moved along the same roads.',
                ],
                [
                    'q' => 'How was silk actually made?',
                    'o' => ['From a plant', 'From sheep bred in China', 'From the cocoons of caterpillars', 'From spider webs'],
                    'c' => 2,
                    'e' => 'From the cocoon of the silkworm moth. China kept the method secret for around three thousand years.',
                ],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'A road that was never a road',
            'location' => 'Central Asia',
            'year' => 1300,
            'image' => 'commons:Caravane sur la Route de la soie - Atlas catalan.jpg',
            'prefer' => 'art',
            'script' => 'Start by throwing away the picture in your head. The Silk Road was not a road. There was no signpost, no surface, nothing anybody could point at. It was a loose braid of tracks, mountain passes, river crossings and oasis towns spread across eight thousand kilometres, and it shifted every generation depending on who was at war and where the water was. Almost nobody travelled the whole thing. Goods did. A bale of silk might change owner thirty times between a workshop in China and a market in Venice, and each merchant only knew the stretch of road in front of him. Even the name is modern: a German geographer invented the phrase Silk Road in 1877, more than four hundred years after the routes had faded. The people who actually walked it would not have known what you meant.',
        ],
        [
            'type' => 'story',
            'chapter' => 'The three thousand year secret',
            'location' => 'China',
            'year' => 750,
            'image' => 'commons:Court Ladies Preparing Newly Woven Silk (cropped).jpg',
            'prefer' => 'art',
            'script' => 'Why silk? Because for three thousand years only China could make it, and nobody else could work out how. Silk is spun by a caterpillar, the silkworm, which eats mulberry leaves and then wraps itself in a single unbroken thread up to nine hundred metres long. Unwind that thread and weave it, and you get a cloth that is light, strong, and shines like water. In Rome it was worth its weight in gold, and senators complained that it was scandalous. China guarded the method with the death penalty. Look at this painting: Chinese court ladies stretching and ironing a length of new silk, made about nine hundred years ago. That quiet domestic scene is the beginning of the whole story. Around the year 550, so the Byzantine historian Procopius says, two monks smuggled silkworm eggs out of Asia hidden inside a hollow walking cane. The secret was out, but by then the roads existed, and everything else had started moving along them.',
        ],

        [
            'type' => 'map',
            'chapter' => 'Where it happened',
            'location' => 'Eurasia',
            'year' => 1271,
            'projection' => 'mercator',
            'script' => 'Here is the scale of the thing. On the left, Venice: a city of merchants living on mud flats, the richest port in Europe. Then Constantinople, the gateway between two worlds. Then Samarkand, a green city of gardens and tiled domes sitting in the middle of an enormous dry emptiness. And on the far right, Chang\'an, the Chinese capital, at the time possibly the largest city on earth. Now measure the space between them with your eye. There are two deserts in there that kill people, and a wall of mountains so high that travellers called it the roof of the world. A caravan crossing that took two years. Keep this picture in your mind, because in 1271 a seventeen year old boy from Venice set out to walk it.',
            'labels' => [
                ['Venice', 12.34, 45.44],
                ['Constantinople', 28.98, 41.01],
                ['Samarkand', 66.98, 39.65],
                ["Chang'an", 108.94, 34.34],
            ],
        ],

        [
            'type' => 'voyage', 'voyage' => 'marco-polo-1271', 'leg' => 0, 'intro' => true,
            'chapter' => 'A seventeen year old leaves Venice',
            'location' => 'Venice',
            'year' => 1271,
            'title' => 'Setting out',
            'date_label' => '1271',
            'story' => 'Marco Polo was seventeen when he left Venice with his father and his uncle, who had already made the journey once and were going back with letters for the Great Khan. He would be forty one when he came home.',
            'images' => [
                'commons:Caravane sur la Route de la soie - Atlas catalan.jpg',
                'commons:Katalanischer Atlas 01.jpg',
            ],
            'prefer' => 'art',
            'script' => 'In 1271, a seventeen year old called Marco Polo sailed out of Venice with his father Niccolò and his uncle Maffeo. The two older men had done this before. They had reached the court of Kublai Khan, the Mongol emperor who now ruled China, and he had asked them to come back and bring scholars and holy oil from Jerusalem. Marco had barely met his father, who had been away for most of his childhood. He was going with two near strangers to a place nobody in Venice could describe. He would be forty one years old before he saw his own city again.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'marco-polo-1271', 'leg' => 3,
            'chapter' => 'The desert that has a voice',
            'location' => 'Persia',
            'year' => 1272,
            'title' => 'Across Persia to the sea',
            'date_label' => '1272',
            'story' => 'The Polos crossed Persia hoping to sail to China from the port of Hormuz. They took one look at the ships there, which were sewn together with coconut fibre rather than nailed, and decided they would rather walk.',
            'images' => [
                'commons:Grave figure depicted camels and their riders on the Silk Route 2.JPG',
                'commons:Katalanischer Atlas 01.jpg',
            ],
            'prefer' => 'art',
            'script' => 'They went down through Persia to Hormuz, meaning to finish the journey by sea. Then they saw the ships. The boats in that harbour were stitched together with rope made from coconut husk instead of being nailed, and Marco wrote that he would not have sailed in one for anything, because they were drowning men constantly. So the Polos turned round and went back north, on foot and by camel, and took the hardest overland route on earth instead. That decision added years to their journey. It is also why we have the book, because the road they chose went through places no European had ever described.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'marco-polo-1271', 'leg' => 5,
            'chapter' => 'The roof of the world',
            'location' => 'The Pamirs',
            'year' => 1273,
            'title' => 'Where fire will not burn properly',
            'date_label' => '1273',
            'story' => 'In the Pamir mountains the caravan climbed to over four thousand metres. Marco noticed something he could not explain: fires there burned dim and pale, and food took far longer to cook. He was describing the effect of thin air at altitude, several centuries before anyone understood why.',
            'images' => [
                'commons:Galerie Tretiakov - Vasili Vasilyevich Vereshchagin - Medrasah Shir-Dhor at Registan place in Samarkand (1869 - 1870).jpg',
                'commons:Grave figure depicted camels and their riders on the Silk Route 2.JPG',
            ],
            'prefer' => 'art',
            'script' => 'Then came the Pamirs, the mountains travellers called the roof of the world. The caravan spent twelve days on a plateau more than four thousand metres up where, Marco says, no birds fly and nothing grows. And he wrote down a detail that tells you he was really paying attention: up there, fires burned strangely dim and pale, and it took much longer to cook anything. He had no idea why. He was describing thin air at high altitude, and nobody in Europe would understand the reason for another four hundred years. When you are deciding whether to believe a traveller, small useless details like that one are worth more than any grand claim.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'marco-polo-1271', 'leg' => 7,
            'chapter' => 'The desert of no return',
            'location' => 'The Taklamakan',
            'year' => 1274,
            'title' => 'Voices in the sand',
            'date_label' => '1274',
            'story' => 'The Taklamakan is one of the driest places on earth. Caravans skirted its edge, oasis to oasis, never crossing the middle. Marco recorded the belief that spirits called travellers away from the road by name, and that men who followed the voices were never seen again.',
            'images' => [
                'commons:Dinastia tang, gruppo di figure in porcellana sancai, dalla provincia dell\'Henan, inizio dell\'VIII sec 02 cammelli.jpg',
                'commons:Mural of Buddha in Mogao Caves, Dunhuang.jpg',
            ],
            'prefer' => 'art',
            'script' => 'The Taklamakan desert is so hostile that its name is often translated as the place you go in and do not come out. Nobody crossed the middle. Caravans crept round the rim, hopping from one oasis town to the next, and if you missed a well you died. Marco wrote down what the caravan men believed about that place: that spirits called out to travellers by their own names, in the voices of their friends, and led them away from the road into the sand, and that whoever followed was never found. That is not a fact about geography. It is a fact about fear, and about what it does to people who are exhausted and a very long way from home.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'marco-polo-1271', 'leg' => 8,
            'chapter' => 'The court of the Great Khan',
            'location' => 'Khanbaliq',
            'year' => 1275,
            'title' => 'Arrival, after three and a half years',
            'date_label' => '1275',
            'story' => 'The Polos reached Kublai Khan in 1275. Marco was twenty one. He would stay in the Khan\'s service for around seventeen years, and claimed to have been sent as an envoy across the empire, which is where much of his book comes from.',
            'images' => [
                'commons:YuanEmperorAlbumKhubilaiPortrait.jpg',
                'commons:Reproduced Mural of 220 cave, Mogao caves, Dunhuang.jpg',
            ],
            'prefer' => 'art',
            'script' => 'Three and a half years after leaving Venice, they arrived. Kublai Khan was the grandson of Genghis, and he ruled the largest land empire there has ever been. Marco was twenty one, and he stayed for around seventeen years. What amazed him most was not the gold. It was the paper. The Khan\'s government took the bark of mulberry trees, made it into sheets, stamped them, and ordered everybody to accept them as money. To a Venetian banker\'s son this was close to sorcery: a government making something worth exactly as much as everyone agreed it was worth. He also described a postal system with ten thousand relay stations, and coal, which he called black stones that burn. Europe had none of it.',
        ],

        [
            'type' => 'story',
            'chapter' => 'In the shoes of a caravan driver',
            'location' => 'The Gobi',
            'year' => 1280,
            'image' => 'commons:Grave figure depicted camels and their riders on the Silk Route 2.JPG',
            'prefer' => 'art',
            'script' => 'Now step out of the Polo family and into the shoes of the person who actually made this work. You are a caravan driver, and you are not going to China. You do one stretch of road, the same stretch, over and over, for your whole working life: maybe forty days out and forty days back between two oasis towns. You have around thirty camels, and you know each of them by temperament. Loading takes two hours before dawn, because you move in the cool and rest in the heat. You are carrying bales you are not allowed to open, and you have no real idea what is in them or where they will end up. Your worries are small and constant: the price of fodder, whether the next well is dry, whether the men at the crossing will want a bribe or a fight, and whether that cough in the grey camel is serious. Nobody will ever write your name down. But every silk dress in Rome, every idea that reached China, every letter between empires, moved because thousands of people like you got up in the dark and did another forty days.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'What actually travelled',
            'location' => 'The Silk Road',
            'year' => 1300,
            'title' => 'Goods, gods and germs',
            'date_label' => 'Roughly 100 BC to 1500 AD',
            'story' => 'Silk went west, and so did paper, gunpowder, the compass, printing, peaches and the idea of a number for nothing. Glass, wool, horses, gold and silver went east. But the most important cargo was never in the bales. Buddhism walked out of India along these roads and became one of the great religions of China, Korea and Japan. Islam moved east along them. Christian and Jewish communities settled the oasis towns. Music, instruments, astronomy and mathematics crossed in both directions until nobody could say who had invented what. And then, in the 1340s, something else used the same roads. The plague we call the Black Death appears to have moved west along the trade routes and reached Europe by ship in 1347, where it killed perhaps a third of everyone alive. A road is not good or bad. It simply carries whatever is put on it.',
            'images' => [
                'commons:Court Ladies Preparing Newly Woven Silk (cropped).jpg',
                'commons:Reproduced Mural of 220 cave, Mogao caves, Dunhuang.jpg',
                'commons:Caravane sur la Route de la soie - Atlas catalan.jpg',
            ],
            'prefer' => 'art',
            'script' => 'Silk went west, and with it paper, gunpowder, printing, the compass, peaches, and the idea of writing a number for nothing. Glass, wool, horses, gold and silver went east. But the most valuable cargo was never in the bales. Buddhism walked out of India along these roads and became one of the great religions of East Asia. Islam moved east the same way. Music, astronomy and mathematics crossed in both directions until you can no longer say who invented what. And then in the thirteen forties something else used the road. The plague we call the Black Death seems to have travelled west along these routes, reached Europe by ship in 1347, and killed something like a third of everybody on the continent. A road is not good or evil. It carries whatever is put on it.',
        ],

        [
            'type' => 'story',
            'chapter' => 'The cave that was walled up',
            'location' => 'Dunhuang',
            'year' => 1000,
            'image' => 'commons:Reproduced Mural of 220 cave, Mogao caves, Dunhuang.jpg',
            'prefer' => 'art',
            'script' => 'At Dunhuang, where the road split to go round either side of the Taklamakan, travellers stopped to pray before the desert and to give thanks after it. Over a thousand years, monks cut hundreds of chapels into a cliff there and covered every surface with paintings. Then, around the year 1000, somebody sealed one small room, filled floor to ceiling with manuscripts, and plastered over the door. It stayed shut for nine hundred years. When it was opened in 1900 there were roughly fifty thousand documents inside, in seventeen languages: contracts, spells, letters, a boy\'s homework with the teacher\'s corrections on it. One of them is a printed scroll dated to the year 868, which makes it the oldest dated printed book in the world. Within a few years European and Japanese expeditions had bought and carried off most of the collection, which is why it now sits in London, Paris, Beijing and Tokyo rather than at Dunhuang. Whether that counted as rescue or as theft is argued about to this day.',
        ],

        [
            'type' => 'voyage', 'voyage' => 'marco-polo-1271', 'leg' => 10,
            'chapter' => 'The long way home',
            'location' => 'The Indian Ocean',
            'year' => 1292,
            'title' => 'Six hundred passengers, eighteen survivors',
            'date_label' => '1291 to 1293',
            'story' => 'The Khan would not let the Polos leave until a Mongol princess needed escorting to Persia by sea. The fleet took two years to cross. Of roughly six hundred people who set out, Marco says only eighteen were still alive at the end.',
            'images' => [
                'commons:Katalanischer Atlas 01.jpg',
                'commons:YuanEmperorAlbumKhubilaiPortrait.jpg',
            ],
            'prefer' => 'art',
            'script' => 'Getting home was harder than getting there, because Kublai Khan did not want to let them go. The excuse finally came when a Mongol princess had to be delivered to a husband in Persia and the land route was too dangerous. The Polos went with the fleet. The voyage took two years through the South China Sea and across the Indian Ocean, and it was a catastrophe. Of around six hundred people who sailed, Marco says eighteen were alive at the end. The princess survived. The Polos survived. They reached Venice in 1295, dressed in Mongol clothes, speaking oddly, after twenty four years away, and the story goes that their own relatives did not recognise them at the door.',
        ],

        [
            'type' => 'story',
            'chapter' => 'A book written in prison',
            'location' => 'Genoa',
            'year' => 1298,
            'image' => 'commons:Katalanischer Atlas 01.jpg',
            'prefer' => 'art',
            'script' => 'Three years after coming home, Marco was captured in a war between Venice and Genoa and put in prison. His cellmate happened to be a professional writer of romances called Rustichello, and to pass the time Marco talked and Rustichello wrote. The result went across Europe in handwritten copies before printing even existed. People called it Il Milione, the million, probably because they thought it contained a million lies. And you should ask the question they asked. Marco never mentions tea. He never mentions chopsticks, or the practice of binding women\'s feet, or the Great Wall. Some historians have argued he never went at all and simply collected other people\'s stories in Persia. Most disagree, because he describes Mongol court procedure, paper money, salt taxes and coal in a level of detail that is hard to fake, and because a Chinese record independently mentions the princess he escorted. The honest answer is that it is very good evidence and not proof. That is what most of history is.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'What the road left behind',
            'location' => 'Eurasia',
            'year' => 1400,
            'title' => 'Why it stopped, and what stayed',
            'date_label' => 'After 1400',
            'story' => 'The overland routes faded for ordinary reasons. The Mongol empire broke apart, so the safe passage it had guaranteed disappeared. The plague thinned the towns. And then European ships found ways to reach Asia by sea, which was cheaper and did not involve crossing two deserts. But the road had already done its work. Paper reached Europe and made printing possible, which made books cheap, which changed who could learn. Gunpowder reached Europe and changed who could rule. Buddhism became a religion of East Asia. And Marco Polo\'s book of unreliable wonders sat in the library of a Genoese sailor called Christopher Columbus, who covered its margins with notes, and who sailed west in 1492 partly because he wanted to reach the country in it.',
            'images' => [
                'commons:Galerie Tretiakov - Vasili Vasilyevich Vereshchagin - Medrasah Shir-Dhor at Registan place in Samarkand (1869 - 1870).jpg',
                'commons:Mural of Buddha in Mogao Caves, Dunhuang.jpg',
                'commons:Caravane sur la Route de la soie - Atlas catalan.jpg',
            ],
            'prefer' => 'art',
            'script' => 'The overland roads faded for unromantic reasons. The Mongol empire broke up, and with it the safe passage it had enforced. The plague emptied the towns. And European ships worked out how to reach Asia by sea, which was cheaper than crossing two deserts. But the road had already done its work. Paper reached Europe and made cheap printed books possible, which changed who was allowed to learn anything. Gunpowder reached Europe and changed who was able to rule. Buddhism became a religion of East Asia. And a copy of Marco Polo\'s book of unreliable wonders ended up in the hands of a Genoese sailor named Christopher Columbus, who filled its margins with notes, and who sailed west in 1492 in part because he wanted to get to the country he had read about in it.',
        ],

        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'What did you learn?',
            'questions' => [
                [
                    'q' => 'Why is calling it "the Silk Road" slightly misleading?',
                    'o' => [
                        'No silk was ever carried on it',
                        'It was many shifting routes, not one road, and the name was invented in 1877',
                        'It was only used for one century',
                        'It was a sea route, not a land route',
                    ],
                    'c' => 1,
                    'e' => 'It was a braid of changing routes. The phrase was coined by a German geographer long after the routes had faded.',
                ],
                [
                    'q' => 'Did most traders travel the whole length of the route?',
                    'o' => [
                        'Yes, most went from China to Europe',
                        'No, goods changed hands many times while each trader worked one stretch',
                        'Only Marco Polo did',
                        'Nobody travelled it at all',
                    ],
                    'c' => 1,
                    'e' => 'Goods travelled the whole way; people usually did not. A bale might change owner dozens of times.',
                ],
                [
                    'q' => 'What did Marco Polo find most astonishing about Kublai Khan\'s empire?',
                    'o' => ['Gold mines', 'Paper money', 'Elephants', 'The Great Wall'],
                    'c' => 1,
                    'e' => 'Money made from mulberry bark, worth whatever the government said it was worth, amazed a Venetian merchant\'s son.',
                ],
                [
                    'q' => 'Apart from goods, what else moved along these roads?',
                    'o' => [
                        'Nothing else',
                        'Religions, inventions, music and also the Black Death',
                        'Only soldiers',
                        'Only silk-making knowledge',
                    ],
                    'c' => 1,
                    'e' => 'Buddhism, Islam, paper, gunpowder, printing and mathematics travelled the same routes, and so did the plague in the 1340s.',
                ],
                [
                    'q' => 'Why do some historians doubt Marco Polo went to China?',
                    'o' => [
                        'His book was written in Latin',
                        'He never mentions tea, chopsticks, foot binding or the Great Wall',
                        'He said he never left Venice',
                        'The book was written a century after he died',
                    ],
                    'c' => 1,
                    'e' => 'Those gaps are the strongest argument against him. Most historians still accept the journey, because his detail on Mongol administration is hard to fake.',
                ],
                [
                    'q' => 'What was found in the sealed cave at Dunhuang?',
                    'o' => [
                        'Gold and silver',
                        'About fifty thousand manuscripts, including the oldest dated printed book',
                        'Silkworm eggs',
                        'A Roman legion\'s armour',
                    ],
                    'c' => 1,
                    'e' => 'Around fifty thousand documents in seventeen languages, including a scroll printed in 868 AD.',
                ],
            ],
        ],
    ],
];
