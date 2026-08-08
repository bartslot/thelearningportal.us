<?php

declare(strict_types=1);

/** Michiel de Ruyter and the Anglo-Dutch wars — a rope-maker's son who ran the sea. */
return [
    'key' => 'Michiel de Ruyter and the wars at sea',
    'title' => 'Michiel de Ruyter and the Wars at Sea',
    'subject' => 'history',
    'language' => 'nl',
    'grade_level' => '6',

    'scenes' => [
        [
            'type' => 'quiz', 'when' => 'pre', 'chapter' => 'What do you already know?',
            'questions' => [
                ['q' => 'Which country did the Dutch Republic fight in the Anglo-Dutch wars?', 'o' => ['France', 'England', 'Sweden', 'Portugal'], 'c' => 1, 'e' => 'Three major naval wars with England between 1652 and 1674.'],
                ['q' => 'What did the Dutch and English mostly fight about?', 'o' => ['Religion', 'Trade and control of the sea', 'A royal marriage', 'Land borders'], 'c' => 1, 'e' => 'Shipping, trade routes and colonies — these were commercial wars.'],
                ['q' => 'What job did Michiel de Ruyter start out doing?', 'o' => ['He was born a nobleman', 'He worked in a rope-walk as a boy and went to sea at 11', 'He was a lawyer', 'He was a priest'], 'c' => 1, 'e' => 'The son of a Vlissingen beer porter, he began as a rope-maker\'s boy.'],
                ['q' => 'What was the Raid on the Medway (1667)?', 'o' => ['A Dutch fleet sailed into England and burned the English fleet at anchor', 'An English victory in the Channel', 'A storm that sank both fleets', 'A trade agreement'], 'c' => 0, 'e' => 'One of the most humiliating defeats in English naval history.'],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'The rope-maker\'s boy', 'location' => 'Vlissingen', 'year' => 1607,
            'image' => 'commons:Bol, Ferdinand - Portrait of Michiel de Ruyter.jpg', 'prefer' => 'art',
            'script' => 'Michiel Adriaenszoon de Ruyter was born in Vlissingen in Zeeland in 1607, the son of a beer porter. He worked in a rope-walk as a boy and went to sea at eleven. He was not from a great family, he had no money and no connections, and in most of Europe at that time that would have been the end of the story. In the Dutch Republic it was not. He rose through merchant shipping, whaling and privateering, and ended up as the most respected admiral in Europe.',
        ],
        [
            'type' => 'story', 'chapter' => 'Why fight England?', 'location' => 'The North Sea', 'year' => 1652,
            'image' => 'zeeslag 17e eeuw Nederlandse vloot', 'prefer' => 'art',
            'script' => 'These wars were not about religion or borders. They were about money. The Dutch carried an enormous share of the world\'s seaborne trade, and England wanted that share. In 1651 the English parliament passed the Navigation Acts, which said goods could only be brought to England in English ships or ships from the country the goods came from — aimed squarely at Dutch shipping. Within a year the two Protestant republics were at war. There would be three of these wars in twenty two years.',
        ],
        [
            'type' => '3d', 'chapter' => 'A gun from a real wreck', 'location' => 'The Klein Hollandia', 'year' => 1672,
            'sketchfab' => 'https://sketchfab.com/3d-models/cannon-17th-c-dutch-warship-klein-hollandia-1552d62eb113493db782d2c07d6d2ddf',
            'script' => 'This is not a reconstruction. It is a scan of a real bronze cannon lying on the seabed, on the wreck of the Klein Hollandia — a Dutch warship of the Rotterdam Admiralty, built in 1654 and sunk in 1672. Turn it around and look at the size of it. A ship of the line carried sixty, seventy, eighty guns like this, on two or three decks, and a battle meant sailing close enough that both sides fired into each other for hours. Marine archaeologists found this wreck off the English coast, and it is now a protected site.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'How a sea battle worked', 'location' => 'The North Sea', 'year' => 1666,
            'title' => 'Line of battle', 'date_label' => '17th century',
            'story' => 'In these wars the fleets learned to fight in a line, one ship behind another, so that every gun on one side could fire at the enemy without hitting a friend. That sounds simple and it was extremely hard: dozens of ships, driven by wind alone, holding formation in smoke so thick that captains could not see the next ship along. Signals were flags. The Four Days\' Battle of 1666 lasted, as the name says, four days, and is one of the longest naval battles ever fought. Thousands of men died in these engagements, most of them from splinters — jagged shards of oak blasted off the hull.',
            'images' => ['Vierdaagse Zeeslag 1666', 'zeeslag schepen 17e eeuw schilderij', 'Willem van de Velde zeeslag'],
            'prefer' => 'art',
            'script' => 'In these wars the fleets learned to fight in a line, one ship behind another, so every gun on one side could fire without hitting a friend. That sounds simple and it was extremely hard: dozens of ships driven by wind alone, holding formation in smoke so thick captains could not see the next ship along. Signals were flags. The Four Days\' Battle of 1666 lasted four days, one of the longest naval battles ever fought. Most of the dead were killed by splinters, jagged shards of oak blasted off the hull.',
        ],
        [
            'type' => 'story', 'chapter' => 'Up the Medway', 'location' => 'Chatham', 'year' => 1667,
            'image' => 'Tocht naar Chatham 1667', 'prefer' => 'art',
            'script' => 'In June 1667 the Dutch did something audacious. With England short of money and its fleet laid up in harbour, a Dutch squadron sailed up the river Medway, broke the great chain strung across it, burned the English ships at their moorings and towed away the Royal Charles, the flagship of the English navy. Its ornamental stern is still on display in the Rijksmuseum in Amsterdam. In England it is remembered as one of the worst humiliations in the history of the Royal Navy. The war ended within weeks.',
        ],
        [
            'type' => 'map', 'chapter' => 'A country facing the sea', 'location' => 'The Netherlands', 'year' => 1667, 'qid' => 'Q55',
            'script' => 'Look at how much of this country is coast. The Republic had no great army and no natural defences on the sea side; it had ships, and admiralties in five different towns that each built and manned their own. Vlissingen, where De Ruyter was born. Rotterdam, whose admiralty built the Klein Hollandia. Den Helder and Texel, where fleets gathered. Amsterdam, which paid for much of it. For a country this size to take on England and France at once was extraordinary.',
            'labels' => [
                ['Vlissingen — De Ruyter\'s birthplace', 3.5736, 51.4426],
                ['Rotterdam — the admiralty', 4.4777, 51.9244],
                ['Texel — the fleet anchorage', 4.7970, 53.0546],
                ['Amsterdam', 4.8952, 52.3702],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'The disaster year', 'location' => 'The Netherlands', 'year' => 1672,
            'image' => 'Rampjaar 1672', 'prefer' => 'art',
            'script' => 'In 1672 — the Rampjaar, the disaster year — the Republic was attacked by France, England, Münster and Cologne all at once. The Dutch said of that year that the people were irrational, the government helpless and the country beyond saving. On land they survived only by flooding a defensive water line across the country. At sea, De Ruyter held. At Solebay and then in a series of battles in 1673 he fought off a combined Anglo-French fleet that badly outnumbered him, and stopped an invasion from ever landing. It is generally considered his finest work.',
        ],
        [
            'type' => 'video', 'chapter' => 'The story in two minutes', 'location' => 'The Netherlands',
            'youtube' => 'https://www.youtube.com/watch?v=6aaTJZ7SKss', 'controls' => true, 'fit' => 'fit',
            'script' => 'This is the official Canon van Nederland clip about Michiel de Ruyter. Watch it, and think about why a country chooses particular people to remember.',
        ],
        [
            'type' => 'story', 'chapter' => 'Bestevaêr', 'location' => 'Amsterdam', 'year' => 1676,
            'image' => 'graf Michiel de Ruyter Nieuwe Kerk', 'prefer' => 'photo',
            'script' => 'His sailors called him Bestevaêr — grandad — which tells you something. He was famous for looking after his crews, for eating the same food they did, and for being personally modest in a century of very grand admirals. He was mortally wounded fighting the French off Sicily and died in 1676. The Republic gave him a state funeral and a marble tomb in the Nieuwe Kerk in Amsterdam, where you can still see it. Even his enemies mourned him: the French king ordered his own coastal batteries to fire a salute as the ship carrying his body passed.',
        ],
        [
            'type' => 'story', 'chapter' => 'A complicated hero', 'location' => 'The Netherlands', 'year' => 2025,
            'image' => 'standbeeld Michiel de Ruyter Vlissingen', 'prefer' => 'photo',
            'script' => 'De Ruyter is a genuine national hero, and the story is more complicated than the statue suggests. The fleets he commanded protected Dutch trade — and that trade included the slave trade; he personally led an expedition in 1664 to retake Dutch slaving forts on the West African coast from the English. Historians and the Dutch navy have been discussing this openly in recent years. A good way to hold both facts is this: he was a brilliant seaman and a decent commander who served a state whose wealth had a brutal foundation. Understanding him means understanding both halves.',
        ],
        [
            'type' => 'quiz', 'when' => 'post', 'chapter' => 'What did you learn?',
            'questions' => [
                ['q' => 'What caused the Anglo-Dutch wars?', 'o' => ['Religion', 'Competition over trade and shipping', 'A disputed border', 'A royal insult'], 'c' => 1, 'e' => 'The English Navigation Acts targeted Dutch shipping, and war followed.'],
                ['q' => 'What was De Ruyter\'s background?', 'o' => ['A noble family', 'A rope-maker\'s boy who went to sea at eleven', 'A university professor', 'A merchant prince'], 'c' => 1, 'e' => 'He rose from the very bottom, which was possible in the Republic in a way it was not elsewhere.'],
                ['q' => 'What happened during the Raid on the Medway in 1667?', 'o' => ['The Dutch burned the English fleet at anchor and towed away its flagship', 'The English captured Amsterdam', 'Both fleets sank in a storm', 'A peace treaty was signed at sea'], 'c' => 0, 'e' => 'The Royal Charles was taken to the Netherlands; its stern is in the Rijksmuseum.'],
                ['q' => 'Why was 1672 called the Rampjaar?', 'o' => ['A plague swept the country', 'France, England, Münster and Cologne attacked at once', 'The harvest failed', 'Amsterdam burned down'], 'c' => 1, 'e' => 'The Republic was attacked from all sides; De Ruyter\'s fleet prevented an invasion by sea.'],
                ['q' => 'Why did sailors call him "Bestevaêr"?', 'o' => ['He was the oldest man in the fleet', 'He looked after his crews and lived like them', 'He owned the ships', 'He had many grandchildren'], 'c' => 1, 'e' => 'It means "grandad" — a mark of affection for how he treated his men.'],
                ['q' => 'What complicates his reputation today?', 'o' => ['He lost most of his battles', 'The trade he protected included the slave trade, and he retook slaving forts in 1664', 'He never went to sea', 'He fought for Spain'], 'c' => 1, 'e' => 'Dutch historians and the navy now discuss both his skill and the system he served.'],
            ],
        ],
    ],
];
