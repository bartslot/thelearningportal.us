<?php

declare(strict_types=1);

/** Columbus 1492 — the crossing, and the world it broke open. Uses the animated voyage map. */
return [
    'key' => 'Columbus and the crossing of 1492',
    'title' => 'Columbus and the Crossing of 1492',
    'subject' => 'history',
    'language' => 'en',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [
        [
            'type' => 'quiz', 'when' => 'pre', 'chapter' => 'What do you already know?',
            'questions' => [
                ['q' => 'What was Columbus actually trying to reach in 1492?', 'o' => ['A new continent', 'Asia, by sailing west', 'The South Pole', 'Africa'], 'c' => 1, 'e' => 'He wanted a western sea route to the riches of Asia — he never knew he had found a continent.'],
                ['q' => 'Which country paid for the voyage?', 'o' => ['Portugal', 'England', 'Spain', 'France'], 'c' => 2, 'e' => 'Isabella of Castile and Ferdinand of Aragon, after Portugal and others said no.'],
                ['q' => 'What were the names of his three ships?', 'o' => ['Santa María, Pinta and Niña', 'Victoria, Trinidad and Concepción', 'Eendracht, Hoorn and Dordrecht', 'Endeavour, Resolution and Discovery'], 'c' => 0, 'e' => 'The Santa María was the flagship; the Pinta and Niña were smaller and faster.'],
                ['q' => 'Did educated Europeans in 1492 believe the Earth was flat?', 'o' => ['Yes, almost all of them', 'No — they knew it was round, but disagreed about its size', 'Only sailors believed it was round', 'Nobody had thought about it'], 'c' => 1, 'e' => 'The round Earth was well established. The real argument was how BIG it is — and Columbus was wrong about that.'],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'A man with a bad calculation', 'location' => 'Genoa', 'year' => 1485,
            'image' => 'Christopher Columbus portrait', 'prefer' => 'art',
            'script' => 'Christopher Columbus was a Genoese sailor with one enormous idea: that you could reach Asia faster by sailing west across the Ocean Sea than by going the long way around Africa. Educated people already knew the Earth was round. What they disagreed about was its size — and the experts who examined Columbus\'s numbers told him, correctly, that he had made the world far too small. If there had been nothing but open ocean between Spain and Asia, he and his crews would have starved long before they arrived. He was saved by a continent he did not know existed.',
        ],
        [
            'type' => 'story', 'chapter' => 'Seven years of no', 'location' => 'Granada', 'year' => 1492,
            'image' => 'Columbus voor Isabella van Castilie', 'prefer' => 'art',
            'script' => 'He asked the king of Portugal, whose mathematicians rejected the plan. He sent his brother to England and France. Everyone said no. Then he spent years following the Spanish court around, waiting. In January 1492 Granada, the last Muslim kingdom in Spain, finally fell to Isabella and Ferdinand — and suddenly they had money and attention to spare. In a contract called the Capitulations of Santa Fe they promised Columbus noble titles and a tenth of everything he found. He got three ships out of the little port of Palos.',
        ],
        [
            'type' => '3d', 'chapter' => 'What a caravel is', 'location' => 'Palos de la Frontera', 'year' => 1492,
            'sketchfab' => 'https://sketchfab.com/3d-models/eendracht-5122ceb52cfd4bf5be518fdf693efbb3',
            'script' => 'Turn this ship around and get a sense of the scale. The Santa María was around twenty three metres long — shorter than two buses. Around forty men lived on her, sleeping on deck or on coils of rope, with no charts for where they were going, food that rotted, and water that went green. The Niña and the Pinta were smaller still. People crossed an unknown ocean in these.',
        ],

        // ── the crossing, on the animated map ────────────────────────────
        [
            'type' => 'voyage', 'voyage' => 'columbus-1492', 'leg' => 0, 'intro' => true,
            'chapter' => 'The Canaries', 'location' => 'La Gomera', 'year' => 1492,
            'title' => 'Last land', 'date_label' => '12 August 1492',
            'story' => 'Before facing the open ocean, Columbus sailed to the Canary Islands to refit and take on water, wood and food. La Gomera was the last land his crews would see for five weeks.',
            'images' => ['La Gomera Canarias', 'caravela siglo XV'],
            'prefer' => 'art',
            'script' => 'Before he dared the open ocean, Columbus sailed first to the Canary Islands, to refit the ships and take on water, wood and food. La Gomera was the last land his crews would see for five weeks.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'columbus-1492', 'leg' => 1,
            'chapter' => 'Thirty-six days', 'location' => 'San Salvador', 'year' => 1492,
            'title' => 'Land at two in the morning', 'date_label' => '12 October 1492',
            'story' => 'For thirty-six days there was nothing but water. The crews grew frightened and then mutinous; Columbus kept two reckonings of the distance, one for himself and a shorter one to show the men. At two in the morning on 12 October a lookout on the Pinta sighted a pale line of cliffs.',
            'images' => ['San Salvador Bahamas island', 'landing of Columbus 1492'],
            'prefer' => 'art',
            'script' => 'For thirty six days there was nothing but water. The crews grew frightened, then mutinous. Columbus kept two reckonings of the distance: one for himself, and a shorter, comforting one to show the men. At two in the morning on the twelfth of October, a lookout on the Pinta saw a pale line of cliffs in the moonlight. They had reached an island in the Bahamas. The people who already lived there, the Taíno, called it Guanahani.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'columbus-1492', 'leg' => 2,
            'chapter' => 'Looking for the Great Khan', 'location' => 'Cuba', 'year' => 1492,
            'title' => 'This must be Asia', 'date_label' => 'October 1492',
            'story' => 'Convinced he was among the islands off the coast of Asia, Columbus sailed on to Cuba and sent a delegation inland with a letter for the Emperor of China. They came back having found villages, tobacco and hospitality, but no emperor.',
            'images' => ['Cuba 16th century map', 'Taino people'],
            'prefer' => 'art',
            'script' => 'Convinced he was among the islands off the coast of Asia, Columbus sailed on to Cuba and sent a delegation inland carrying a letter addressed to the Emperor of China. They came back having found villages, hospitality and tobacco, but no emperor. He would go to his grave insisting these islands were part of Asia.',
        ],
        [
            'type' => 'voyage', 'voyage' => 'columbus-1492', 'leg' => 3,
            'chapter' => 'Wreck and return', 'location' => 'Hispaniola', 'year' => 1492,
            'title' => 'The Santa María runs aground', 'date_label' => '25 December 1492',
            'story' => 'On Christmas night the Santa María drifted onto a reef off Hispaniola while the helm was left with a ship\'s boy. She could not be saved. Her timbers were used to build a small fort, La Navidad, and thirty-nine men stayed behind. When Columbus returned a year later, every one of them was dead.',
            'images' => ['Hispaniola 16th century', 'Santa Maria shipwreck 1492'],
            'prefer' => 'art',
            'script' => 'On Christmas night the Santa María drifted onto a reef off Hispaniola while the helm had been left to a ship\'s boy. She could not be saved. Her timbers were used to build a small fort called La Navidad, and thirty nine men stayed behind in it. When Columbus came back a year later, every one of them was dead.',
        ],

        [
            'type' => 'gallery', 'chapter' => 'The people who were already there', 'location' => 'The Caribbean', 'year' => 1493,
            'title' => 'The Taíno', 'date_label' => '1492 and after',
            'story' => 'The islands Columbus reached were not empty. They were home to the Taíno, who farmed cassava and sweet potato, lived in large villages, played a ball game in stone courts, and navigated between the islands in enormous canoes. Columbus wrote in his own journal that they were generous and gentle, and in the very next breath that they would make good servants and could easily be conquered. Within about fifty years, through forced labour, violence and above all European diseases against which they had no immunity, the Taíno population of Hispaniola had collapsed almost completely. It is one of the largest demographic catastrophes in recorded history.',
            'images' => ['Taino Caribbean indigenous', 'cassava Caribbean agriculture', 'Hispaniola indigenous village'],
            'prefer' => 'art',
            'script' => 'The islands Columbus reached were not empty. They were home to the Taíno, who farmed cassava, lived in large villages, and navigated between the islands in enormous canoes. Columbus wrote in his journal that they were generous and gentle, and in the very next sentence that they would make good servants and could easily be conquered. Within about fifty years, through forced labour, violence and above all European diseases, the Taíno population of Hispaniola had collapsed almost completely.',
        ],
        [
            'type' => 'map', 'chapter' => 'Two worlds meet', 'location' => 'Spain', 'year' => 1500, 'qid' => 'Q29',
            'script' => 'Here is the small corner of Europe the voyage set out from. Palos de la Frontera, the little Andalusian port that supplied the ships and most of the crew. Granada, where the monarchs finally said yes. Barcelona, where Columbus was received in triumph on his return, with Taíno people and parrots paraded through the streets. Sailing west from here in 1492 permanently joined two halves of the world that had developed separately for thousands of years.',
            'labels' => [
                ['Palos de la Frontera — departure', -6.8931, 37.2306],
                ['Granada — the contract', -3.5986, 37.1773],
                ['Barcelona — the triumphal return', 2.1734, 41.3851],
            ],
        ],
        [
            'type' => 'video', 'chapter' => 'Another view', 'location' => 'The Atlantic',
            'youtube' => 'https://www.youtube.com/watch?v=-E9T6UWaDRA', 'controls' => true, 'fit' => 'fit',
            'script' => 'Watch this account of the voyage and its aftermath, and listen for whose perspective is being used at each point in the story.',
        ],
        [
            'type' => 'story', 'chapter' => 'The Columbian Exchange', 'location' => 'The Atlantic World', 'year' => 1550,
            'image' => 'Columbian exchange crops', 'prefer' => 'art',
            'script' => 'What followed 1492 was the biggest biological event in human history, and historians call it the Columbian Exchange. From the Americas came potatoes, maize, tomatoes, cacao, chillies, tobacco and rubber, and they transformed diets everywhere — there is no Italian tomato sauce and no Dutch chip without it. Going the other way came wheat, sugar, horses, cattle — and smallpox, measles and influenza, which killed an estimated ninety percent of the indigenous population of the Americas within a century. It also began the Atlantic slave trade, as Europeans replaced the labour force they had destroyed.',
        ],
        [
            'type' => 'story', 'chapter' => 'How should we tell this?', 'location' => 'The Americas', 'year' => 2025,
            'image' => 'Columbus statue monument', 'prefer' => 'photo',
            'script' => 'For centuries Columbus was taught as a straightforward hero: brave, visionary, the man who discovered America. Almost every part of that is contested now. He did not discover a land where millions already lived. He was not the first European to reach the Americas — Norse sailors had reached Newfoundland five hundred years earlier. He was a poor governor who was arrested and shipped home in chains by Spain\'s own officials for his brutality. And the voyage genuinely did change the world permanently. Many countries have replaced Columbus Day with Indigenous Peoples\' Day, and statues have come down. How would you tell it?',
        ],
        [
            'type' => 'quiz', 'when' => 'post', 'chapter' => 'What did you learn?',
            'questions' => [
                ['q' => 'What was Columbus wrong about?', 'o' => ['The Earth being round', 'The size of the Earth — he thought it was far smaller', 'The existence of the sea', 'The use of a compass'], 'c' => 1, 'e' => 'Experts correctly told him he had underestimated the distance; a continent saved him.'],
                ['q' => 'Why did Spain finally fund the voyage in 1492?', 'o' => ['A new tax', 'The fall of Granada freed up money and attention', 'The Pope ordered it', 'Portugal asked them to'], 'c' => 1, 'e' => 'With the long war for Granada over, Isabella and Ferdinand could take the risk.'],
                ['q' => 'What happened to the Santa María?', 'o' => ['She sailed home safely', 'She ran aground off Hispaniola on Christmas night', 'She was captured by pirates', 'She was burned in Cuba'], 'c' => 1, 'e' => 'Her timbers built the fort La Navidad, where 39 men were left — all later found dead.'],
                ['q' => 'Who was already living on the islands Columbus reached?', 'o' => ['Nobody', 'The Taíno', 'The Aztecs', 'The Inca'], 'c' => 1, 'e' => 'The Taíno farmed, built villages and navigated between islands in large canoes.'],
                ['q' => 'What was the Columbian Exchange?', 'o' => ['A trade agreement', 'The transfer of crops, animals and diseases between the hemispheres', 'A Spanish bank', 'A type of ship'], 'c' => 1, 'e' => 'It reshaped diets worldwide — and killed an estimated 90% of indigenous Americans through disease.'],
                ['q' => 'Which statement is historically accurate?', 'o' => ['Columbus was the first European in the Americas', 'Norse sailors reached Newfoundland about 500 years earlier', 'He proved the Earth was round', 'He reached mainland Asia'], 'c' => 1, 'e' => 'Norse settlement at L\'Anse aux Meadows predates 1492 by roughly five centuries.'],
            ],
        ],
    ],
];
