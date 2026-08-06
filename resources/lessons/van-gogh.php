<?php

declare(strict_types=1);

/** Vincent van Gogh — ten years, nine hundred paintings, and almost no recognition. */
return [
    'key' => 'Vincent van Gogh',
    'title' => 'Vincent van Gogh: a life in ten years',
    'subject' => 'history',
    'language' => 'en',
    'grade_level' => '6',
    'map_style' => 'satellite',

    'scenes' => [
        [
            'type' => 'quiz', 'when' => 'pre', 'chapter' => 'What do you already know?',
            'questions' => [
                ['q' => 'For how many years did Van Gogh work as a painter?', 'o' => ['About 10', 'About 25', 'About 40', 'His whole life'], 'c' => 0, 'e' => 'He started at 27 and died at 37 — about ten years of work.'],
                ['q' => 'Roughly how many paintings did he sell during his life?', 'o' => ['Hundreds', 'Dozens', 'Very few — perhaps only a handful', 'None at all'], 'c' => 2, 'e' => 'Almost none. He was supported by his brother Theo.'],
                ['q' => 'Which of these is a famous Van Gogh painting?', 'o' => ['The Night Watch', 'The Starry Night', 'The Milkmaid', 'The Kiss'], 'c' => 1, 'e' => 'De Sterrennacht, painted in 1889 from an asylum window.'],
                ['q' => 'Who supported Vincent financially and received his letters?', 'o' => ['His brother Theo', 'His father', 'The King', 'A gallery in Paris'], 'c' => 0, 'e' => 'Theo van Gogh, an art dealer, paid for nearly everything and kept the letters.'],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'The failed everything', 'location' => 'Zundert', 'year' => 1853,
            'image' => 'commons:Vincent van Gogh - Self-Portrait - Google Art Project.jpg', 'prefer' => 'art',
            'script' => 'Vincent van Gogh was born in Zundert, in Brabant, in 1853, the son of a Protestant minister. For most of his twenties he failed at everything he tried. He worked as an art dealer and was dismissed. He tried teaching in England. He trained for the ministry and was rejected as unsuitable, then went as a lay preacher to a coal-mining district in Belgium, where he gave away his possessions and lived so poorly that the church removed him for excessive zeal. He was twenty seven, with no career and no money, when he decided to become an artist.',
        ],
        [
            'type' => 'story', 'chapter' => 'Painting the poor', 'location' => 'Nuenen', 'year' => 1885,
            'image' => 'De Aardappeleters Van Gogh', 'prefer' => 'art',
            'script' => 'His early Dutch work is nothing like the Van Gogh on the posters. It is dark, brown and heavy, and it is about labour: weavers at their looms, peasants digging, women carrying sacks of coal. In 1885, in the village of Nuenen, he painted The Potato Eaters — five peasants eating a meal of potatoes by lamplight, with hands he deliberately made coarse and ugly. He wrote to Theo that he wanted people to see that these hands had dug the earth the food came from. Critics hated it. He considered it his first real painting.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'Colour arrives', 'location' => 'Paris', 'year' => 1886,
            'title' => 'Two years in Paris', 'date_label' => '1886 – 1888',
            'story' => 'In 1886 Vincent moved in with Theo in Paris, and everything changed. He saw Impressionist paintings for the first time — Monet, Pissarro, Seurat — and he saw Japanese woodblock prints, which he collected obsessively. Out went the brown. In came bright, unmixed colour laid on in short visible strokes, and flat bold shapes borrowed straight from the Japanese prints. He painted flowers, Paris streets, and his own face over and over because he could not afford models. In two years he taught himself an entirely new way of painting.',
            'images' => ['Van Gogh zonnebloemen', 'Van Gogh Parijs schilderij 1887', 'Japanse prent Van Gogh'],
            'prefer' => 'art',
            'script' => 'In 1886 Vincent moved in with Theo in Paris, and everything changed. He saw Impressionist paintings for the first time, and Japanese woodblock prints, which he collected obsessively. Out went the brown. In came bright unmixed colour in short visible strokes, and flat bold shapes borrowed from the Japanese prints. He painted flowers, streets, and his own face over and over, because he could not afford models.',
        ],
        [
            'type' => '3d', 'chapter' => 'The Yellow House bedroom', 'location' => 'Arles', 'year' => 1888,
            'sketchfab' => 'https://sketchfab.com/3d-models/camera-limits-demo-van-gogh-bedroom-in-arles-daefab319a584e559443e39ff05e84fa',
            'script' => 'This is Van Gogh\'s bedroom in the Yellow House at Arles, built as a room you can actually turn around in. He painted it three times. Look at the tilting floor and the leaning furniture: for years people assumed he simply could not draw perspective, but the room really was crooked, and he wanted the picture to feel like rest. He wrote to Theo that the colours were meant to suggest sleep. Two chairs, two pillows — he was expecting a friend.',
        ],
        [
            'type' => 'story', 'chapter' => 'The south, and the ear', 'location' => 'Arles', 'year' => 1888,
            'image' => 'Van Gogh Het gele huis Arles', 'prefer' => 'art',
            'script' => 'In 1888 Vincent went south to Arles, looking for stronger light. He rented the Yellow House and dreamed of founding a community of artists there. Only one came: Paul Gauguin. They painted together for nine weeks and argued constantly, and it ended in December when Vincent, in crisis, cut off part of his own left ear. Gauguin left. Vincent went into hospital. It is the single most famous thing about him, and it is worth remembering that it happened in one bad week of a ten-year working life.',
        ],
        [
            'type' => 'map', 'chapter' => 'A short, restless life', 'location' => 'The Netherlands', 'year' => 1885, 'qid' => 'Q55',
            'script' => 'Vincent moved constantly. He was born in Zundert in Brabant, worked in The Hague, painted the weavers and the potato eaters in Nuenen, and studied briefly in Antwerp before leaving for Paris and then the south of France. The Dutch years produced the dark pictures; the French years produced the colour. Today the largest collection of his work is in Amsterdam, in a museum built around what his family kept.',
            'labels' => [
                ['Zundert — born 1853', 4.6597, 51.4694],
                ['Nuenen — The Potato Eaters', 5.5500, 51.4722],
                ['The Hague', 4.3007, 52.0705],
                ['Van Gogh Museum, Amsterdam', 4.8810, 52.3584],
            ],
        ],
        [
            'type' => 'story', 'chapter' => 'Painting from an asylum', 'location' => 'Saint-Rémy', 'year' => 1889,
            'image' => 'Van Gogh Sterrennacht', 'prefer' => 'art',
            'script' => 'In May 1889 Vincent voluntarily entered an asylum at Saint-Rémy, where he had periods of severe illness separated by periods of intense clarity and work. In one of those clear periods he painted the view from his barred window at night, added a village and a cypress tree from memory, and produced The Starry Night — a sky rolling and churning like water. It is now one of the most reproduced images on earth. He thought it was a failure.',
        ],
        [
            'type' => 'video', 'chapter' => 'The story in two minutes', 'location' => 'The Netherlands',
            'youtube' => 'https://www.youtube.com/watch?v=Oo_Y7ve1jJY', 'controls' => true, 'fit' => 'fit',
            'script' => 'This is the official Canon van Nederland clip about Vincent van Gogh. Watch how a life that looked like failure at the time is told now.',
        ],
        [
            'type' => 'story', 'chapter' => 'Seventy days, seventy paintings', 'location' => 'Auvers-sur-Oise', 'year' => 1890,
            'image' => 'Van Gogh Korenveld met kraaien', 'prefer' => 'art',
            'script' => 'In May 1890 Vincent moved to Auvers-sur-Oise, near Paris, under the care of a doctor who was himself an amateur painter. In roughly seventy days there he made about seventy paintings — an extraordinary burst of work, including wide wheatfields under heavy skies. On the twenty seventh of July he was wounded by a gunshot in a field, and he died two days later, aged thirty seven. Theo, who had carried him financially and emotionally for a decade, died six months after him.',
        ],
        [
            'type' => 'gallery', 'chapter' => 'The letters', 'location' => 'The Netherlands', 'year' => 1914,
            'title' => 'Why we know him so well', 'date_label' => '1872 – 1890',
            'story' => 'We know more about Van Gogh\'s thinking than about almost any other painter, because roughly nine hundred of his letters survive — most of them to Theo. In them he explains exactly what he is trying to do with a colour, worries about money, describes the weather, and criticises his own work mercilessly. Theo\'s widow, Johanna van Gogh-Bonger, is the reason any of it survived. After both brothers died she kept the paintings, organised exhibitions and, in 1914, published the letters. She is largely why Vincent is famous at all, and for a long time nobody mentioned her.',
            'images' => ['Van Gogh brief Theo', 'Johanna van Gogh-Bonger', 'Van Gogh Museum Amsterdam'],
            'prefer' => 'photo',
            'script' => 'We know more about Van Gogh\'s thinking than about almost any other painter, because around nine hundred of his letters survive, most of them to Theo. In them he explains exactly what he is trying to do with a colour, worries about money, and criticises his own work mercilessly. Theo\'s widow, Johanna van Gogh-Bonger, is why any of it survived: after both brothers died she kept the paintings, organised exhibitions, and published the letters. She is largely why Vincent is famous at all.',
        ],
        [
            'type' => 'story', 'chapter' => 'What we get wrong about him', 'location' => 'Amsterdam', 'year' => 2025,
            'image' => 'Van Gogh Museum interieur', 'prefer' => 'photo',
            'script' => 'The story people usually tell is of a mad genius who painted on instinct. The letters say something different. Van Gogh read constantly, studied colour theory, copied other artists to learn from them, and worked with tremendous discipline through illness. He made about nine hundred paintings and eleven hundred drawings in ten years. He was ill, and he was poor, and he was also a serious, thoughtful, professional artist who knew exactly what he was doing. Both things are true, and the second one is the part that usually gets left out.',
        ],
        [
            'type' => 'quiz', 'when' => 'post', 'chapter' => 'What did you learn?',
            'questions' => [
                ['q' => 'What did Van Gogh do before becoming a painter?', 'o' => ['Art dealer, teacher and lay preacher', 'Sailor', 'Doctor', 'Farmer'], 'c' => 0, 'e' => 'He failed at all three before starting to paint at twenty-seven.'],
                ['q' => 'What is The Potato Eaters about?', 'o' => ['A royal banquet', 'Peasants eating by lamplight, with hands that had dug the earth', 'A harvest festival', 'A restaurant in Paris'], 'c' => 1, 'e' => 'He wanted viewers to connect the coarse hands with the food they had grown.'],
                ['q' => 'What changed his painting in Paris?', 'o' => ['Impressionism and Japanese prints', 'A new type of canvas', 'Studying in Italy', 'A wealthy patron'], 'c' => 0, 'e' => 'Bright colour and flat bold shapes replaced his dark Dutch palette.'],
                ['q' => 'Where did he paint The Starry Night?', 'o' => ['In Amsterdam', 'From a window in an asylum at Saint-Rémy', 'On a ship', 'In Zundert'], 'c' => 1, 'e' => 'He painted the night view from his barred window, adding a village from memory.'],
                ['q' => 'Who preserved and published Van Gogh\'s work and letters?', 'o' => ['Paul Gauguin', 'His brother Theo\'s widow, Johanna van Gogh-Bonger', 'The French government', 'His father'], 'c' => 1, 'e' => 'Johanna kept the paintings, arranged exhibitions and published the letters in 1914.'],
                ['q' => 'Which statement fits the evidence in his letters?', 'o' => ['He painted purely on instinct with no study', 'He studied colour theory and worked with great discipline', 'He never wrote anything down', 'He was rich and famous in his lifetime'], 'c' => 1, 'e' => 'The letters show a well-read, deliberate professional — not only a "mad genius".'],
            ],
        ],
    ],
];
