<?php

declare(strict_types=1);

/**
 * Anne Frank and the Secret Annex — Dutch Canon lesson.
 *
 * Written for roughly ages 10–14. The Holocaust is told honestly but without graphic imagery:
 * the camp scenes lean on memorials and the historical record rather than atrocity photographs,
 * and the lesson keeps returning to Anne as a writer and a person, not only as a victim.
 */
return [
    'key' => 'Anne Frank and the Secret Annex',
    'title' => 'Anne Frank and the Secret Annex',
    'subject' => 'history',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [

        // ── Prior knowledge ──────────────────────────────────────────────
        [
            'type' => 'quiz',
            'when' => 'pre',
            'chapter' => 'What do you already know?',
            'questions' => [
                [
                    'q' => 'Anne Frank became world famous because of something she wrote. What was it?',
                    'o' => ['A diary', 'A newspaper', 'A book of poems', 'A school textbook'],
                    'c' => 0,
                    'e' => 'Anne kept a diary for just over two years while she was in hiding.',
                ],
                [
                    'q' => 'In which city did Anne Frank and her family go into hiding?',
                    'o' => ['Berlin', 'Amsterdam', 'Paris', 'London'],
                    'c' => 1,
                    'e' => 'They hid in a secret annex behind her father\'s business on the Prinsengracht in Amsterdam.',
                ],
                [
                    'q' => 'During which war did Anne Frank live in hiding?',
                    'o' => ['The First World War', 'The Second World War', 'The Eighty Years\' War', 'The Cold War'],
                    'c' => 1,
                    'e' => 'The Netherlands was occupied by Nazi Germany from 1940 to 1945.',
                ],
                [
                    'q' => 'How many people hid together in the Secret Annex?',
                    'o' => ['Two', 'Four', 'Eight', 'Twelve'],
                    'c' => 2,
                    'e' => 'Eight people shared the hiding place for just over two years.',
                ],
            ],
        ],

        // ── Before the war ───────────────────────────────────────────────
        [
            'type' => 'story',
            'chapter' => 'A family in Frankfurt',
            'location' => 'Frankfurt am Main',
            'year' => 1929,
            'image' => 'commons:AnneFrank1940.jpg',
            'prefer' => 'photo',
            'script' => 'Annelies Marie Frank was born in Frankfurt, Germany, on the twelfth of June 1929. Everyone called her Anne. Her father Otto was a businessman who had fought for Germany in the First World War. Her mother Edith looked after Anne and her older sister Margot. They were a Jewish family, and in the Frankfurt of Anne\'s early childhood that was simply one ordinary fact among many. Nobody in that comfortable apartment could have guessed what the next ten years would bring.',
        ],
        [
            'type' => 'story',
            'chapter' => 'Escape to Amsterdam',
            'location' => 'Amsterdam',
            'year' => 1934,
            'image' => 'Merwedeplein Amsterdam',
            'prefer' => 'photo',
            'script' => 'In 1933 Adolf Hitler came to power, and life for Jewish families in Germany changed almost overnight. New laws stripped away their rights, one after another. Otto Frank decided the family had to leave. They moved to Amsterdam, where Otto started a company selling pectin for making jam, and later spices. Anne learned Dutch quickly, made friends on the Merwedeplein square, and grew into a talkative, funny, curious girl. For six years the Netherlands felt like safety.',
        ],

        // ── Where this happened ──────────────────────────────────────────
        [
            'type' => 'map',
            'chapter' => 'Where it happened',
            'location' => 'The Netherlands',
            'year' => 1942,
            'qid' => 'Q55',
            'projection' => 'mercator',
            'script' => 'Look carefully at this map, because the whole of Anne\'s story fits inside it. Here is Amsterdam, where the Frank family made their new home. On the Prinsengracht canal stands the building we now call the Anne Frank House, with the hiding place behind it. And far to the north east, in the empty heathland of Drenthe, is Westerbork: the transit camp from which more than one hundred thousand Jewish people were put on trains to the east. The distance between these places is small. The journey between them changed everything.',
            // Labels must be far enough apart to stay legible once the camera fits the whole
            // country — two Amsterdam pins 1 km apart overprint into mush at this scale.
            // These three also trace the journey: hiding place → transit camp → destination.
            'labels' => [
                ['Amsterdam — the Secret Annex', 4.8840, 52.3752],
                ['Westerbork transit camp', 6.6069, 52.9186],
                ['Bergen-Belsen', 9.9075, 52.7580],
            ],
        ],

        // ── Occupation ───────────────────────────────────────────────────
        [
            'type' => 'story',
            'chapter' => 'May 1940: the occupation',
            'location' => 'Rotterdam',
            'year' => 1940,
            'image' => 'Rotterdam bombardment 1940 ruins',
            'prefer' => 'photo',
            'script' => 'On the tenth of May 1940, German forces invaded the Netherlands. Four days later bombers destroyed the heart of Rotterdam, and the Dutch army surrendered. The country the Franks had chosen as their refuge was now occupied. At first daily life looked almost normal. But the occupiers had brought their laws with them, and those laws were about to reach into every Jewish home in the Netherlands.',
        ],
        [
            'type' => 'gallery',
            'chapter' => 'The net tightens',
            'location' => 'Amsterdam',
            'year' => 1941,
            'title' => 'One rule at a time',
            'date_label' => '1940 – 1942',
            'story' => 'The occupiers did not take everything at once. They took it piece by piece, so that each new rule seemed almost bearable on its own. Jewish people were removed from their jobs and their government posts. Jewish children were ordered out of their schools and into separate Jewish ones — Anne and Margot had to leave their classmates behind. Jews were banned from parks, cinemas, swimming pools, trams and the shops of their choosing. They had to hand in their bicycles. From May 1942, everyone aged six and over had to wear a yellow star stitched to their clothing, with the word Jood — Jew — printed across it. Step by step, a whole group of people was cut out of everyday life, in full view of their neighbours.',
            'images' => [
                'commons:Yellow Jewish Badge - Dutch Version.png',
                'Amsterdam Jewish quarter 1941',
                'Verboden voor Joden sign Netherlands',
            ],
            'prefer' => 'photo',
            'script' => 'The occupiers did not take everything at once. They took it piece by piece. Jewish people lost their jobs. Jewish children were ordered out of their schools. Jews were banned from parks, cinemas, swimming pools and trams, and had to hand in their bicycles. And from May 1942, everyone aged six and over had to wear a yellow star with the word Jood — Jew — stitched onto their clothes. Step by step, a whole group of people was cut out of everyday life, in full view of their neighbours.',
        ],

        // ── Going into hiding ────────────────────────────────────────────
        [
            'type' => 'story',
            'chapter' => 'The birthday diary',
            'location' => 'Amsterdam',
            'year' => 1942,
            'image' => 'Anne Frank diary facsimile',
            'prefer' => 'photo',
            'script' => 'On the twelfth of June 1942, Anne turned thirteen. Among her presents was a small notebook with a red and white checked cover. She decided to write in it as though she were writing to a friend, and she gave that friend a name: Kitty. Dear Kitty, she began. She could not have known that this ordinary birthday present would become one of the most read books in the world.',
        ],
        [
            'type' => 'story',
            'chapter' => '6 July 1942: into hiding',
            'location' => 'Prinsengracht 263',
            'year' => 1942,
            'image' => 'commons:Amsterdam - Prinsengracht 263.JPG',
            'prefer' => 'photo',
            'script' => 'Three weeks later, a letter arrived ordering sixteen year old Margot to report for a work camp in Germany. The family did not wait. Early the next morning, in pouring rain, they walked across Amsterdam wearing several layers of clothing at once, because a suitcase would have given them away. They disappeared into the empty rooms behind Otto\'s business at Prinsengracht 263. A week later the Van Pels family joined them, and in November came Fritz Pfeffer, a dentist. Eight people, hidden behind a bookcase.',
        ],

        // ── The 3D annex ─────────────────────────────────────────────────
        [
            'type' => '3d',
            'chapter' => 'Inside the Secret Annex',
            'location' => 'The Secret Annex',
            'year' => 1942,
            'sketchfab' => 'https://sketchfab.com/3d-models/the-diary-of-anne-frank-scenic-design-5f77e95fe1bd4bd8b1289c5ba96b6d56',
            'script' => 'Turn the model and look at how small it really was. Just over fifty square metres, spread across two floors and an attic, for eight people. Downstairs, Otto, Edith, Margot and Anne. Upstairs, the Van Pels family and their kitchen, which doubled as a living room. Anne shared her narrow room first with Margot, then with Mister Pfeffer. She pasted pictures of film stars and a young princess on the wall beside her bed, because it was the only way to make a hiding place feel a little like a home.',
        ],
        [
            'type' => 'gallery',
            'chapter' => 'Two years of silence',
            'location' => 'The Secret Annex',
            'year' => 1943,
            'title' => 'The rules of staying hidden',
            'date_label' => 'July 1942 – August 1944',
            'story' => 'Between roughly nine in the morning and half past five in the evening, the eight people in the Annex could not run a tap, flush a toilet, cough loudly or walk in shoes. The warehouse workers below must never suspect anyone was above them. They lived by the chimes of the Westerkerk clock, which Anne came to love. And they lived because of five people who risked everything for them: Miep Gies, Bep Voskuijl, Johannes Kleiman, Victor Kugler and Bep\'s father Johan. These helpers carried in food, books, and news of the war, week after week, for more than two years. If they had been caught, they too would have been arrested.',
            'images' => [
                'Anne Frank House bookcase entrance',
                'commons:Westerkerk tower, Amsterdam 2505.jpg',
                'Miep Gies',
            ],
            'prefer' => 'photo',
            'script' => 'From nine in the morning until half past five, nobody in the Annex could run a tap, flush a toilet, or even walk in shoes. The warehouse workers below must never suspect. They lived by the chimes of the Westerkerk clock, which Anne came to love. And they lived because of the helpers — Miep Gies, Bep Voskuijl, Johannes Kleiman, Victor Kugler and Johan Voskuijl — who carried in food and books and news for more than two years, knowing that if they were caught they would be arrested too.',
        ],
        [
            'type' => 'story',
            'chapter' => 'A writer at work',
            'location' => 'The Secret Annex',
            'year' => 1944,
            'image' => 'commons:AnneFrankSchoolPhoto.jpg',
            'prefer' => 'photo',
            'script' => 'Anne did not only record what happened. She argued with her mother on the page, fell in and out of love with Peter van Pels, laughed at the adults, and worried about who she really was. In March 1944 the family heard a Dutch minister on the radio from London, asking people to keep their diaries and letters as evidence of the occupation. Anne began rewriting her diary as a book. She wanted to be a journalist and a writer, and she meant to publish it after the war. She was fifteen years old, and she was already very good.',
        ],

        // ── Video ────────────────────────────────────────────────────────
        [
            'type' => 'video',
            'chapter' => 'Her own words',
            'location' => 'Anne Frank House',
            'youtube' => 'https://www.youtube.com/watch?v=iIXx0rdRfPk',
            'controls' => true,
            'fit' => 'fit',
            'script' => 'The Anne Frank House made a video diary that imagines what Anne would have filmed if she had been given a camera instead of a notebook. Watch, and listen for how ordinary she sounds — because that is the point.',
        ],

        // ── The end of the hiding place ──────────────────────────────────
        [
            'type' => 'story',
            'chapter' => '4 August 1944: the arrest',
            'location' => 'Prinsengracht 263',
            'year' => 1944,
            'image' => 'Anne Frank House Amsterdam facade',
            'prefer' => 'photo',
            'script' => 'On the morning of the fourth of August 1944, a car pulled up outside Prinsengracht 263. Police officers walked straight to the bookcase. All eight people in hiding were arrested, along with two of the helpers. To this day nobody knows for certain who betrayed them, or whether they were found by chance during a different investigation. Historians are still working on it. What we do know is that after the arrest, Miep Gies went back up into the ransacked rooms, found Anne\'s papers scattered on the floor, and gathered them up.',
        ],
        [
            'type' => 'story',
            'chapter' => 'Westerbork, Auschwitz, Bergen-Belsen',
            'location' => 'Westerbork',
            'year' => 1945,
            'image' => 'Westerbork camp memorial',
            'prefer' => 'photo',
            'script' => 'The eight were sent first to Westerbork, and then, on the third of September 1944, on the very last train that ever ran from Westerbork to Auschwitz. Edith Frank died there. Anne and Margot were moved on to Bergen-Belsen in Germany, a camp with no food, no medicine and no shelter worth the name. Both sisters caught typhus. Both died there in February 1945, within days of each other. Anne was fifteen. British troops liberated the camp only a few weeks later.',
        ],
        [
            'type' => 'story',
            'chapter' => 'The father who came back',
            'location' => 'Amsterdam',
            'year' => 1945,
            'image' => 'Otto Frank',
            'prefer' => 'photo',
            'script' => 'Of the eight people who hid in the Annex, only Otto Frank survived. He came back to Amsterdam in June 1945 and slowly learned that his wife and both his daughters were dead. It was then that Miep Gies took the papers she had saved out of her desk drawer and handed them to him. She had never read a word of them. Otto read his daughter\'s diary and said afterwards that he had never known her. In 1947 he published it, under the title Anne had chosen herself: Het Achterhuis — The Secret Annex.',
        ],

        // ── Legacy ───────────────────────────────────────────────────────
        [
            'type' => 'gallery',
            'chapter' => 'Why we still read it',
            'location' => 'Anne Frank House',
            'year' => 1960,
            'title' => 'One voice out of six million',
            'date_label' => '1947 to today',
            'story' => 'Anne\'s diary has been translated into more than seventy languages and read by tens of millions of people. In 1960 the building on the Prinsengracht opened as a museum, and Otto Frank insisted the Annex be left empty — no furniture, just bare rooms — so that visitors would feel the absence. Around a million people a year now climb those steep stairs. Six million Jewish people were murdered in the Holocaust, a number so large it stops meaning anything. Anne\'s diary works because it does the opposite: it gives you one person, in detail, arguing with her mother and worrying about her hair, until you cannot pretend she is a statistic.',
            'images' => [
                'commons:Amsterdam (NL), Anne-Frank-Huis, 2022 (3).jpg',
                'commons:Anne Frank Statue in Amsterdam (509090009).jpg',
                'commons:Anne Frank House Model.JPG',
            ],
            'prefer' => 'photo',
            'script' => 'Anne\'s diary has been translated into more than seventy languages. In 1960 the building on the Prinsengracht opened as a museum, and Otto insisted the Annex stay empty — bare rooms, no furniture — so that visitors would feel the absence. Six million Jewish people were murdered in the Holocaust, a number so large it stops meaning anything. Anne\'s diary does the opposite. It gives you one person, in detail, until you cannot pretend she is a statistic.',
        ],
        [
            'type' => 'story',
            'chapter' => 'In spite of everything',
            'location' => 'Amsterdam',
            'year' => 1944,
            'image' => 'Anne Frank memorial Amsterdam',
            'prefer' => 'photo',
            'script' => 'On the fifteenth of July 1944, three weeks before the arrest, Anne wrote a sentence that people have argued about ever since. In spite of everything, she wrote, I still believe that people are truly good at heart. She wrote it knowing exactly what was happening outside. Read the whole passage and you will find she also wrote about hearing the approaching thunder that will destroy us too. She was not naive. She was choosing, deliberately, what to hold on to. That is why we still read her.',
        ],

        // ── Assessment ───────────────────────────────────────────────────
        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'What did you learn?',
            'questions' => [
                [
                    'q' => 'Why did the Frank family leave Germany for the Netherlands in 1933?',
                    'o' => [
                        'Otto Frank was offered a better job',
                        'Hitler came to power and stripped Jewish people of their rights',
                        'They wanted Anne to learn Dutch',
                        'Their house in Frankfurt was destroyed',
                    ],
                    'c' => 1,
                    'e' => 'After Hitler took power in 1933, new laws made life increasingly dangerous for Jewish families, so Otto moved them to Amsterdam.',
                ],
                [
                    'q' => 'What event pushed the family into hiding on 6 July 1942?',
                    'o' => [
                        'Margot was ordered to report to a work camp',
                        'Their business was closed down',
                        'Their house was bombed',
                        'Anne was expelled from school',
                    ],
                    'c' => 0,
                    'e' => 'A call-up letter for sixteen-year-old Margot arrived, and the family went into hiding the very next morning.',
                ],
                [
                    'q' => 'Who kept Anne\'s papers safe after the arrest?',
                    'o' => ['Otto Frank', 'Peter van Pels', 'Miep Gies', 'Fritz Pfeffer'],
                    'c' => 2,
                    'e' => 'Miep Gies gathered the scattered pages and kept them in a drawer, unread, until she could give them to Otto.',
                ],
                [
                    'q' => 'Why did Anne begin rewriting her diary in 1944?',
                    'o' => [
                        'Her teacher asked her to',
                        'She heard a radio appeal to keep wartime writing, and wanted to publish a book',
                        'She had run out of paper',
                        'Her father told her to',
                    ],
                    'c' => 1,
                    'e' => 'A Dutch minister broadcasting from London asked people to preserve diaries as evidence, and Anne began reworking hers into a book.',
                ],
                [
                    'q' => 'Which member of the eight people in hiding survived the war?',
                    'o' => ['Anne', 'Margot', 'Edith', 'Otto'],
                    'c' => 3,
                    'e' => 'Otto Frank was the only one of the eight to survive, and he published his daughter\'s diary in 1947.',
                ],
                [
                    'q' => 'Why does the Anne Frank House keep the Secret Annex empty of furniture?',
                    'o' => [
                        'The original furniture was lost in a fire',
                        'So that visitors feel the absence of the people who lived there',
                        'To make room for more visitors',
                        'Because furniture would damage the floors',
                    ],
                    'c' => 1,
                    'e' => 'Otto Frank insisted the rooms stay bare, so the emptiness itself speaks to visitors.',
                ],
            ],
        ],
    ],
];
