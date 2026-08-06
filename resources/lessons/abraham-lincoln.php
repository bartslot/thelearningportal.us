<?php

declare(strict_types=1);

/**
 * Abraham Lincoln — the war, the Proclamation, and a man who changed his mind. Roughly ages 11–14.
 *
 * Two things this lesson refuses to flatten. First, Lincoln did not begin as an abolitionist and
 * said things in 1858 that are ugly to read now; the honest story is one of a man changing under
 * pressure, which is more useful to a child than a saint. Second, enslaved people were not simply
 * handed their freedom: hundreds of thousands walked out and took it, and the Proclamation followed
 * that fact as much as it caused it. Frederick Douglass, who admired and criticised Lincoln in the
 * same speech, is used as the lesson's honest witness.
 *
 * The 1860s are the first properly photographed war, so this lesson pins photographs as well as
 * paintings. Winslow Homer carries the ordinary soldier's point of view.
 */
return [
    'key' => 'Abraham Lincoln',
    'title' => 'Abraham Lincoln',
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
                    'q' => 'Abraham Lincoln was president during which war?',
                    'o' => ['The War of Independence', 'The American Civil War', 'The First World War', 'The war with Mexico'],
                    'c' => 1,
                    'e' => 'The Civil War, fought between the northern states and the southern states from 1861 to 1865.',
                ],
                [
                    'q' => 'What was the Emancipation Proclamation?',
                    'o' => [
                        'A law ending the war',
                        'An order declaring enslaved people in the rebelling states to be free',
                        'A speech at Gettysburg',
                        'A treaty with Britain',
                    ],
                    'c' => 1,
                    'e' => 'Issued on 1 January 1863. It applied to the states in rebellion, which is a detail that matters a great deal.',
                ],
                [
                    'q' => 'How long was the Gettysburg Address?',
                    'o' => ['About two minutes', 'About an hour', 'Three hours', 'A whole day'],
                    'c' => 0,
                    'e' => 'Two hundred and seventy two words, spoken in roughly two minutes, after another speaker had talked for two hours.',
                ],
                [
                    'q' => 'How did Lincoln die?',
                    'o' => ['Of illness', 'In battle', 'He was assassinated', 'In an accident'],
                    'c' => 2,
                    'e' => 'He was shot at Ford\'s Theatre on 14 April 1865, five days after the main Confederate army surrendered.',
                ],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'A year and a half of school',
            'location' => 'Kentucky',
            'year' => 1816,
            'image' => 'commons:Abraham Lincoln O-80 by Gardner, 1863.jpg',
            'prefer' => 'photo',
            'script' => 'He was born in 1809 in a one room log cabin in Kentucky, on a farm that failed. His family moved to Indiana when he was seven. His mother died when he was nine. Added together, across his whole childhood, he had somewhere around eighteen months of formal schooling. Everything else he taught himself, borrowing books and reading by firelight, and he grew up to be a lawyer and then a politician largely by deciding to be one. He was very tall, awkward looking, and he knew it and made jokes about it before anyone else could. He also suffered from long stretches of deep depression, which his friends wrote about openly and which he never really hid. Keep the cabin in mind, because in 1860 the United States elected this man president, and eleven states decided that they would rather leave the country than be governed by him.',
        ],

        [
            'type' => 'map',
            'chapter' => 'A country splitting in half',
            'location' => 'The United States',
            'year' => 1861,
            'projection' => 'mercator',
            'script' => 'Three places, and the line between them is the story. Here is Springfield, Illinois, where Lincoln lived and worked as a lawyer. Here is Washington, where he went in 1861 to be president. And down here is Charleston, South Carolina, where the first shots were fired. The disagreement tearing the country apart was slavery, and specifically whether it should be allowed to spread into the new western states. Roughly four million people were enslaved in the southern states in 1860. They were not a side issue in the argument. They were the property the argument was about, and they were also people, with names, who were listening to all of it. When Lincoln won the election in November 1860, without being on the ballot in ten southern states at all, South Carolina voted to leave the United States within six weeks. Six more states followed before he had even taken office.',
            'labels' => [
                ['Springfield, Illinois', -89.65, 39.80],
                ['Washington', -77.04, 38.91],
                ['Charleston', -79.93, 32.78],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'What Lincoln actually believed in 1858',
            'location' => 'Illinois',
            'year' => 1858,
            'image' => 'commons:Abraham Lincoln O-80 by Gardner, 1863.jpg',
            'prefer' => 'photo',
            'script' => 'Here is the part that gets left out of the statues. In 1858, debating in front of white audiences in Illinois, Lincoln said plainly that he was not in favour of making Black and white people socially and politically equal, and that while there was a difference he was in favour of the white race having the superior position. He was, at that time, opposed to slavery spreading westwards, not to slavery itself where it already existed. For years he supported the idea of encouraging free Black Americans to leave the country and settle elsewhere. Those are his words and his positions, and they are not comfortable. Do not skip past them, because they are what makes the rest of his life interesting. The question this lesson is really asking is not whether Lincoln was good. It is whether a person can be pushed, by events and by other people, into becoming something better than they started as. Because over the next seven years, that is what happens.',
        ],

        [
            'type' => 'story',
            'chapter' => 'In the shoes of a private soldier',
            'location' => 'Virginia',
            'year' => 1862,
            'image' => 'commons:Winslow Homer - Home, Sweet Home - Google Art Project.jpg',
            'prefer' => 'art',
            'script' => 'Step into the camp. You are eighteen, or you say you are. You joined up in the first weeks because everybody did, and because everybody agreed it would be over by Christmas. It is not over. You have discovered that war is mostly not battle. It is walking, and waiting, and being wet, and food that is always the same, and disease, which will kill roughly twice as many men in this war as bullets will. This painting is by Winslow Homer, who went and lived with the army and drew what he saw. Two soldiers stand outside their tent, doing nothing at all. In the background the regimental band is playing, and the tune, which every man in that camp knows, is Home, Sweet Home. Nothing is happening in this picture. That is exactly why it is one of the truest things ever painted about a war: the long, dull, homesick middle, which is where a soldier actually spends his life.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'The day the country saw what it was doing',
            'location' => 'Antietam',
            'year' => 1862,
            'title' => 'Antietam, 17 September 1862',
            'date_label' => '17 September 1862',
            'story' => 'Around twenty three thousand men were killed, wounded or went missing at Antietam in a single day. It is still the bloodiest day in American history. And something happened afterwards that had never happened before: the photographer Alexander Gardner arrived while the dead were still on the field, and photographed them where they lay. When those pictures were exhibited in New York a few weeks later, ordinary people queued to see them, and one newspaper wrote that if the photographer had not brought bodies and laid them in the streets, he had done something very like it. Until that moment, war had reached people at home as paintings of officers on horseback. Now it reached them as photographs of dead boys in a field. This is the beginning of everything we now argue about regarding whether the public should be shown what war actually looks like.',
            'images' => [
                'commons:Confederate dead gathered for burial at Antietam.jpg',
                'commons:The Army of the Potomac - A Sharp Shooter on Picket Duty.jpg',
                'commons:Winslow Homer - A Drink for a Wounded Soldier (c. 1863).jpg',
            ],
            'prefer' => 'photo',
            'script' => 'On the seventeenth of September 1862, at a creek called Antietam, around twenty three thousand men were killed, wounded or lost in one day. It is still the bloodiest single day in American history. And then something happened that had never happened before. A photographer called Alexander Gardner reached the field while the dead were still lying on it, and photographed them where they had fallen. A few weeks later those pictures went on show in New York, and crowds queued to see them, and a newspaper wrote that if the photographer had not brought bodies and laid them in the streets, he had done something very like it. Before this, war came home as paintings of officers on horseback. From now on it came home as photographs of dead boys in a field.',
        ],

        [
            'type' => 'story',
            'chapter' => 'People who freed themselves',
            'location' => 'The South',
            'year' => 1862,
            'image' => 'commons:The Army of the Potomac - A Sharp Shooter on Picket Duty.jpg',
            'prefer' => 'art',
            'script' => 'While politicians in Washington argued about what to do, enslaved people were already deciding it for them. From the first months of the war, men, women and children walked off plantations and towards the Union army, sometimes for days, sometimes carrying nothing. The army had no idea what to do with them. One general began calling them contraband of war, which was a legal trick meaning enemy property that could be seized, and which had the effect that they were not sent back. Word spread. By the end of the war several hundred thousand people had freed themselves this way. They built camps, they worked, they cooked and dug and drove wagons for the army, and eventually they enlisted in it. Remember this the next time someone says that Lincoln freed the slaves. He signed the order. But an enormous number of people had already voted with their feet, and the order was in part the government catching up with something ordinary people had made unstoppable.',
        ],

        [
            'type' => 'story',
            'chapter' => 'The first of January, 1863',
            'location' => 'Washington',
            'year' => 1863,
            'image' => 'commons:First Reading of the Emancipation Proclamation of President Lincoln by Francis Bicknell Carpenter.png',
            'prefer' => 'art',
            'script' => 'On the first of January 1863 the Emancipation Proclamation came into effect, and it is the most misunderstood document in American history. Read it carefully and you find it declares free the enslaved people in the states then in rebellion, which were exactly the places where the United States government had no power to free anybody. In the slave-holding states that had stayed loyal, it changed nothing at all. Lincoln issued it as a war measure, using his powers as commander in chief, because that was the only legal ground he had. So it can look like an empty gesture. It was not. It changed what the war was for. Every mile the Union army advanced from that day now turned into actual freedom for real people. It allowed Black men to enlist, and around one hundred eighty thousand did. And it made it politically impossible for Britain or France to help the South, because they were not going to fight on the side of slavery. A limited document can still be a hinge that a whole war turns on.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'Gettysburg',
            'location' => 'Gettysburg',
            'year' => 1863,
            'title' => 'Three days, and then two minutes',
            'date_label' => '1 to 3 July, and 19 November 1863',
            'story' => 'In July 1863 the Confederate army came north into Pennsylvania and met the Union army at a small town called Gettysburg. Three days of fighting left around fifty thousand men killed, wounded or missing. The Confederates never mounted an invasion of the north again. Four months later there was a ceremony to dedicate a cemetery for the dead. The main speaker was Edward Everett, the most famous orator in America, and he spoke for two hours. Then Lincoln stood up and spoke for about two minutes, two hundred and seventy two words, and did something quietly enormous: he never mentioned slavery, or the South, or a single general, and instead recast the entire war as a test of whether a country founded on the idea that all men are created equal could survive at all. Everett wrote to him the next day and said he wished he had come as near to the central idea of the occasion in two hours as Lincoln had in two minutes.',
            'images' => [
                'commons:Battle of Gettysburg by Peter F Rothermel.jpg',
                'commons:Crowd of citizens, soldiers, and etc. with Lincoln at Gettysburg. - NARA - 529085 -crop.jpg',
                'commons:Winslow Homer - A Drink for a Wounded Soldier (c. 1863).jpg',
            ],
            'prefer' => 'photo',
            'script' => 'In July 1863 the two armies collided at a small Pennsylvania town called Gettysburg, and in three days around fifty thousand men were killed, wounded or lost. Four months later, at the dedication of the cemetery, the famous orator Edward Everett spoke for two hours. Then Lincoln stood up and spoke for about two minutes. Two hundred and seventy two words. He did not mention slavery, or the South, or any general. Instead he quietly redefined what the whole war was about: whether a nation founded on the idea that all people are created equal could survive at all. Everett wrote to him the next day to say he wished he had come as near the heart of the occasion in two hours as Lincoln had in two minutes.',
        ],

        [
            'type' => 'story',
            'chapter' => 'With malice toward none',
            'location' => 'Washington',
            'year' => 1865,
            'image' => 'commons:Abraham Lincoln delivering his second inaugural address as President of the United States, Washington, D.C. LCCN2009633604.jpg',
            'prefer' => 'photo',
            'script' => 'By early 1865 the war was ending and Lincoln had changed. In January the Thirteenth Amendment passed Congress, abolishing slavery everywhere in the United States permanently, which the Proclamation had never done. In March he was sworn in for a second term, and gave a speech that almost nobody expected. He did not claim that God was on the North\'s side. He said both sides read the same Bible and prayed to the same God, and that the war might be a punishment falling on the whole country, north and south together, for two hundred and fifty years of slavery. And then he finished: with malice toward none, with charity for all, let us bind up the nation\'s wounds. In the photograph of that day, standing in the crowd not far below him, is John Wilkes Booth.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'Six days',
            'location' => 'Washington',
            'year' => 1865,
            'title' => 'Surrender, then Ford\'s Theatre',
            'date_label' => '9 to 15 April 1865',
            'story' => 'On 9 April 1865 Lee surrendered his army to Grant at Appomattox. Five days later, on Good Friday, Lincoln went to a comedy at Ford\'s Theatre. Booth, an actor who knew the building well, walked into the box behind him and shot him in the head. Lincoln died the next morning. He was fifty six. The funeral train carried his body seventeen hundred miles back to Springfield over two weeks, stopping in city after city, and something like a million people stood beside the tracks to watch it pass. It is worth being clear-eyed about what the murder cost. Lincoln had wanted to rebuild the South with, in his words, malice toward none. His successor did not, Congress fought bitterly over it, and the promises made to four million newly free people were steadily abandoned over the following thirty years. What replaced them lasted for a century.',
            'images' => [
                'commons:The Assassination of President Lincoln at Ford\'s Theatre, Washington D.C., April 14th, 1865 MET DP831798.jpg',
                'commons:The Peacemakers 1868.jpg',
                'commons:Abraham Lincoln O-80 by Gardner, 1863.jpg',
            ],
            'prefer' => 'photo',
            'script' => 'On the ninth of April 1865, Lee surrendered to Grant. Five days later, on Good Friday, Lincoln went to see a comedy at Ford\'s Theatre. John Wilkes Booth, an actor who knew that building well, walked into the box behind him and shot him in the head. He died the next morning, aged fifty six. The funeral train took his body seventeen hundred miles home to Springfield over two weeks, and something like a million people stood along the tracks to watch it go by. And here is the cost of those six days. Lincoln had wanted to rebuild the South with malice toward none. His successor did not. The promises made to four million newly freed people were abandoned over the next thirty years, and what replaced them lasted a hundred more.',
        ],

        [
            'type' => 'story',
            'chapter' => 'What Frederick Douglass said',
            'location' => 'Washington',
            'year' => 1876,
            'image' => 'commons:The Peacemakers 1868.jpg',
            'prefer' => 'art',
            'script' => 'The fairest verdict on Lincoln came from a man who had been enslaved himself. Frederick Douglass met him three times, pushed him hard, and admired him. In 1876, speaking at the unveiling of a monument, Douglass refused to give the easy speech. He told the crowd that Lincoln was preeminently the white man\'s President, and that Black Americans were at best his step-children. And then, in the same speech, he said that measured against the country Lincoln had to lead and the opinions he had to move, he had been swift, zealous and determined, and that taking him all in all they could not have been better off with anyone else. Both halves. That is what a grown-up judgement of a historical person looks like: not a hero and not a villain, but a man who started in one place, was pushed by four million people and a war, and moved further than almost anyone expected him to.',
        ],

        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'What did you learn?',
            'questions' => [
                [
                    'q' => 'What did the Emancipation Proclamation actually do?',
                    'o' => [
                        'It ended slavery everywhere in the United States',
                        'It declared free the enslaved people in the states then in rebellion',
                        'It freed only children',
                        'It was a speech, not an order',
                    ],
                    'c' => 1,
                    'e' => 'It applied to the rebelling states, where the government had no power at the time. Slavery was abolished everywhere only by the Thirteenth Amendment.',
                ],
                [
                    'q' => 'Why is it wrong to say Lincoln simply "freed the slaves"?',
                    'o' => [
                        'He had nothing to do with it',
                        'Hundreds of thousands of enslaved people had already freed themselves by walking to Union lines',
                        'Slavery had already ended before the war',
                        'Congress did it without him',
                    ],
                    'c' => 1,
                    'e' => 'Self-emancipation on a huge scale forced the issue. The Proclamation was partly the government catching up with what people had already done.',
                ],
                [
                    'q' => 'What was new about Alexander Gardner\'s photographs of Antietam?',
                    'o' => [
                        'They were the first colour photographs',
                        'They showed the civilian public actual dead soldiers on the field',
                        'They were taken from the air',
                        'They were painted afterwards',
                    ],
                    'c' => 1,
                    'e' => 'Before this the public saw war as heroic paintings. Gardner brought home photographs of the dead where they fell.',
                ],
                [
                    'q' => 'What did Lincoln do in the Gettysburg Address?',
                    'o' => [
                        'Announced the end of the war',
                        'Redefined the war as a test of whether a nation founded on equality could survive',
                        'Named the generals responsible',
                        'Declared slavery abolished',
                    ],
                    'c' => 1,
                    'e' => 'In 272 words he never mentioned slavery or the South, and recast the whole war around the idea that all people are created equal.',
                ],
                [
                    'q' => 'How did Lincoln\'s own views on slavery and race change?',
                    'o' => [
                        'They never changed',
                        'He began opposing only its spread, and said things about racial equality he later moved far beyond',
                        'He was an abolitionist from childhood',
                        'He became more opposed to emancipation over time',
                    ],
                    'c' => 1,
                    'e' => 'In 1858 he opposed slavery spreading, not slavery itself, and denied believing in social and political equality. He moved a very long way by 1865.',
                ],
                [
                    'q' => 'How did Frederick Douglass judge Lincoln in 1876?',
                    'o' => [
                        'As a flawless hero',
                        'As entirely a villain',
                        'Both critically, as "the white man\'s President", and generously, as swift and determined given what he had to move',
                        'He refused to speak about him',
                    ],
                    'c' => 2,
                    'e' => 'Douglass gave both judgements in the same speech, which is what a mature assessment of a historical figure looks like.',
                ],
            ],
        ],
    ],
];
