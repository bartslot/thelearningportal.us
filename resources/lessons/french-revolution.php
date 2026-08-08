<?php

declare(strict_types=1);

/**
 * La Révolution française et l'ascension de Napoléon — roughly ages 14–17.
 *
 * Written in FRENCH, on purpose: it is the demo lesson French visitors meet on the landing page
 * (config/demo.php, by_country.FR), and a French teacher should hear their own language.
 * ScriptLanguage::detect picks the narration voice up from the script itself, so nothing else
 * needs configuring — but every script below must stay in French for that to hold.
 *
 * Structure is deliberate: two map scenes bracket the Revolution (where power sat in 1789, then
 * what Europe did about it), and Bonaparte's rise is told on the animated route map — the
 * `napoleon-1796` voyage in resources/js/timemap/voyages.json — because "how did an artillery
 * officer end up running France" is a question about MOVEMENT, and arrows on a map answer it
 * better than any paragraph.
 *
 * Image queries are French: the corpus (Europeana, Commons, the paintings table) indexes these
 * works under their French titles, and searching in English returns modern travel photos.
 */
return [
    'key' => 'La Révolution française',
    'title' => 'La Révolution française et l\'ascension de Napoléon',
    'subject' => 'histoire',
    'language' => 'fr',
    'grade_level' => '10',
    'tone' => 'storytelling',

    'scenes' => [

        [
            'type' => 'quiz',
            'when' => 'pre',
            'chapter' => 'Ce que vous savez déjà',
            'questions' => [
                [
                    'q' => 'En quelle année la Bastille est-elle prise ?',
                    'o' => ['1769', '1789', '1799', '1815'],
                    'c' => 1,
                    'e' => 'Le 14 juillet 1789. La date est devenue la fête nationale française.',
                ],
                [
                    'q' => 'Qui règne sur la France au début de la Révolution ?',
                    'o' => ['Louis XIV', 'Louis XVI', 'Napoléon Bonaparte', 'Charles X'],
                    'c' => 1,
                    'e' => 'Louis XVI, roi depuis 1774, et le dernier roi de l\'Ancien Régime.',
                ],
                [
                    'q' => 'Que réunit-on à Versailles en mai 1789 ?',
                    'o' => ['Les états généraux', 'Le Congrès de Vienne', 'La Convention', 'Le Directoire'],
                    'c' => 0,
                    'e' => 'Les états généraux, qui n\'avaient plus été convoqués depuis 1614.',
                ],
                [
                    'q' => 'Comment Bonaparte prend-il le pouvoir en 1799 ?',
                    'o' => ['Par une élection', 'Par un coup d\'État', 'Par héritage', 'Par un référendum'],
                    'c' => 1,
                    'e' => 'Le coup d\'État du 18 brumaire an VIII, le 9 novembre 1799.',
                ],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'Un royaume à bout de souffle',
            'location' => 'France',
            'year' => 1788,
            'image' => 'Ancien Régime France paysans XVIIIe siècle gravure',
            'prefer' => 'painting',
            'script' => 'À la fin des années 1780, la France est le pays le plus peuplé d\'Europe occidentale, et elle est ruinée. Les guerres, et surtout l\'aide apportée aux Américains contre l\'Angleterre, ont vidé les caisses. La société est divisée en trois ordres : le clergé, la noblesse, et le tiers état, c\'est-à-dire tous les autres, soit environ quatre-vingt-dix-huit pour cent de la population. Ce sont eux qui paient l\'essentiel de l\'impôt. Deux mauvaises récoltes font monter le prix du pain, qui représente parfois la moitié du budget d\'une famille ouvrière. Le roi Louis XVI n\'est pas un tyran ; il est indécis, et il a besoin d\'argent.',
        ],

        [
            'type' => 'map',
            'chapter' => 'Où se trouve le pouvoir',
            'location' => 'Paris et Versailles',
            'year' => 1789,
            'qid' => 'Q142',
            'script' => 'Regardez ces deux points. Versailles, à une vingtaine de kilomètres de Paris : c\'est là que vit le roi, entouré de sa cour, loin de la ville. Et Paris, où vivent six cent mille personnes, où l\'on imprime les journaux, où l\'on discute dans les cafés, et où le pain manque. Toute la Révolution tient dans la distance entre ces deux points, et dans le fait que la foule finira par la parcourir : en octobre 1789, des milliers de Parisiennes marchent sur Versailles et ramènent le roi à Paris.',
            'labels' => [
                ['Versailles — la cour', 2.1204, 48.8049],
                ['Paris — la ville', 2.3522, 48.8566],
                ['La Bastille', 2.3692, 48.8532],
                ['Les Tuileries', 2.3272, 48.8635],
            ],
        ],

        [
            'type' => 'story',
            'chapter' => 'Le serment du Jeu de paume',
            'location' => 'Versailles',
            'year' => 1789,
            'image' => 'Serment du Jeu de paume David',
            'prefer' => 'painting',
            'script' => 'En mai 1789, le roi convoque les états généraux pour faire accepter de nouveaux impôts. Très vite, la question devient : comment vote-t-on ? Par ordre, et le tiers état est toujours minoritaire ; par tête, et il l\'emporte. Le tiers état se proclame alors Assemblée nationale. Le vingt juin, trouvant leur salle fermée, les députés se réunissent dans une salle de jeu de paume et jurent de ne pas se séparer avant d\'avoir donné une constitution à la France. C\'est le moment où la souveraineté change de mains : elle n\'appartient plus au roi, mais à la nation.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'Le 14 juillet',
            'location' => 'Paris',
            'year' => 1789,
            'title' => 'La prise de la Bastille',
            'date_label' => '14 juillet 1789',
            'story' => 'La Bastille est une vieille forteresse-prison qui domine un quartier ouvrier de Paris. Le 14 juillet 1789, la foule y vient chercher de la poudre. Il n\'y a que sept prisonniers à l\'intérieur. Ce que la Bastille représente compte bien plus que ce qu\'elle contient : le pouvoir d\'enfermer quelqu\'un sans jugement, sur simple lettre du roi. Sa chute apprend à toute l\'Europe qu\'une foule peut prendre une forteresse royale.',
            'images' => [
                'Prise de la Bastille 14 juillet 1789 peinture',
                'Bastille démolition 1789 gravure',
                'Révolution française sans-culottes gravure',
            ],
            'prefer' => 'painting',
            'script' => 'La Bastille est une vieille forteresse-prison qui domine un quartier ouvrier de Paris. Le quatorze juillet 1789, la foule y vient chercher de la poudre. Il n\'y a que sept prisonniers à l\'intérieur. Mais ce que la Bastille représente compte bien plus que ce qu\'elle contient : le pouvoir d\'enfermer quelqu\'un sans jugement, sur simple lettre de cachet du roi. Sa chute apprend à toute l\'Europe qu\'une foule peut prendre une forteresse royale.',
        ],

        [
            'type' => 'story',
            'chapter' => 'Les droits de l\'homme',
            'location' => 'Paris',
            'year' => 1789,
            'image' => 'Déclaration des droits de l\'homme et du citoyen 1789',
            'prefer' => 'painting',
            'script' => 'Le vingt-six août 1789, l\'Assemblée adopte la Déclaration des droits de l\'homme et du citoyen. Dix-sept articles, écrits pour être compris de tous. Les hommes naissent et demeurent libres et égaux en droits. La loi est l\'expression de la volonté générale. Nul ne doit être inquiété pour ses opinions. C\'est un texte français qui devient immédiatement universel : il sera traduit, recopié et invoqué pendant deux siècles, y compris contre la France elle-même. Il a pourtant ses angles morts, et des femmes comme Olympe de Gouges le disent tout de suite.',
        ],

        [
            'type' => 'story',
            'chapter' => 'La République et la Terreur',
            'location' => 'Paris',
            'year' => 1793,
            'image' => 'Robespierre portrait Révolution française',
            'prefer' => 'painting',
            'script' => 'La monarchie tombe en 1792, la République est proclamée, et Louis XVI est jugé puis guillotiné en janvier 1793. La France est alors en guerre contre presque toute l\'Europe et en guerre civile chez elle. Le Comité de salut public, où domine Robespierre, gouverne par la peur : c\'est la Terreur. Des milliers de personnes sont exécutées, y compris des révolutionnaires. En juillet 1794, Robespierre est renversé et exécuté à son tour. La Révolution a inventé la démocratie moderne et, en même temps, montré comment elle peut se dévorer.',
        ],

        [
            'type' => 'map',
            'chapter' => 'L\'Europe des rois contre-attaque',
            'location' => 'Europe',
            'year' => 1793,
            'qid' => 'Q46',
            'projection' => 'mercator',
            'script' => 'Les rois d\'Europe regardent Paris avec effroi : si le peuple français peut renverser son roi, les leurs le peuvent aussi. L\'Autriche et la Prusse envahissent dès 1792 ; l\'Angleterre, l\'Espagne et d\'autres suivent. La France est encerclée. Pour se défendre, elle invente la levée en masse : des centaines de milliers de citoyens-soldats. Cette armée nouvelle, nombreuse et motivée, cherchera bientôt des chefs à la hauteur — et l\'un d\'eux a vingt-six ans.',
            'labels' => [
                ['Paris', 2.3522, 48.8566],
                ['Vienne — l\'Autriche', 16.3738, 48.2082],
                ['Berlin — la Prusse', 13.4050, 52.5200],
                ['Londres — l\'Angleterre', -0.1276, 51.5072],
                ['Toulon — la flotte anglaise, 1793', 5.9280, 43.1242],
            ],
        ],

        [
            'type' => 'voyage',
            'voyage' => 'napoleon-1796',
            'leg' => 0,
            'intro' => true,
            'chapter' => 'La campagne d\'Italie',
            'location' => 'Nice',
            'year' => 1796,
            'title' => 'Une armée en haillons',
            'date_label' => 'avril 1796',
            'story' => 'Bonaparte reçoit le commandement de l\'armée d\'Italie : trente-huit mille hommes mal nourris, mal payés, mal équipés. En un mois, il sépare les Piémontais des Autrichiens et les bat séparément. La vitesse est son arme principale.',
            'images' => ['Bonaparte campagne d\'Italie 1796 peinture', 'Bonaparte au pont d\'Arcole Gros'],
            'prefer' => 'painting',
            'script' => 'Suivez la flèche. En mars 1796, un général de vingt-six ans reçoit le commandement de l\'armée d\'Italie : trente-huit mille hommes mal nourris, mal payés, mal équipés, sur un front que personne à Paris ne considère comme important. En un mois, Bonaparte fait ce que ses adversaires croyaient impossible : il s\'insère entre les Piémontais et les Autrichiens, et les bat séparément. Sa véritable arme, c\'est la vitesse.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'napoleon-1796',
            'leg' => 1,
            'chapter' => 'Lodi, puis Milan',
            'location' => 'Milan',
            'year' => 1796,
            'title' => 'Le pont de Lodi',
            'date_label' => 'mai 1796',
            'story' => 'À Lodi, Bonaparte lance ses grenadiers sur un pont battu par l\'artillerie autrichienne. La légende du chef invincible naît là, et il l\'entretient lui-même : il fait imprimer ses propres bulletins de victoire.',
            'images' => ['Bataille du pont de Lodi 1796', 'Entrée de Bonaparte à Milan 1796'],
            'prefer' => 'painting',
            'script' => 'À Lodi, le dix mai, il lance ses grenadiers sur un pont battu par l\'artillerie autrichienne. Cinq jours plus tard il entre dans Milan sous les acclamations. C\'est là que naît la légende du jeune chef invincible, et il faut bien voir qu\'il l\'entretient lui-même : il fait imprimer ses propres bulletins de victoire et les envoie à Paris, où on les lit à voix haute.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'napoleon-1796',
            'leg' => 3,
            'chapter' => 'Rivoli et la marche sur Vienne',
            'location' => 'Leoben',
            'year' => 1797,
            'title' => 'Le vainqueur dicte la paix',
            'date_label' => 'janvier – avril 1797',
            'story' => 'Après Rivoli et la chute de Mantoue, Bonaparte marche vers Vienne et signe les préliminaires de Leoben — sans attendre les ordres du Directoire. Un général vient de faire sa propre politique étrangère.',
            'images' => ['Bataille de Rivoli 1797', 'Traité de Campo-Formio 1797'],
            'prefer' => 'painting',
            'script' => 'Après la victoire de Rivoli et la chute de Mantoue, il marche vers Vienne et signe lui-même les préliminaires de paix à Leoben, sans attendre les instructions du gouvernement. Regardez bien ce que la carte montre : un général de la République vient de conduire sa propre politique étrangère. À Paris, le Directoire est trop faible pour le lui reprocher.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'napoleon-1796',
            'leg' => 4,
            'chapter' => 'L\'expédition d\'Égypte',
            'location' => 'Alexandrie',
            'year' => 1798,
            'title' => 'Trente-huit mille hommes et cent soixante-sept savants',
            'date_label' => 'mai – juillet 1798',
            'story' => 'La flotte quitte Toulon, échappe à Nelson, prend Malte au passage et débarque en Égypte. À bord, avec les soldats, cent soixante-sept savants : c\'est de cette expédition que naîtront l\'égyptologie et la pierre de Rosette.',
            'images' => ['Expédition d\'Égypte 1798 débarquement Alexandrie', 'Description de l\'Égypte planche'],
            'prefer' => 'painting',
            'script' => 'En mai 1798, la flotte quitte Toulon. L\'objectif officiel est de couper la route des Indes aux Anglais ; l\'objectif réel est aussi de tenir Bonaparte occupé loin de Paris. Il échappe à Nelson, prend Malte au passage, et débarque en Égypte. Avec les soldats voyagent cent soixante-sept savants, dessinateurs et ingénieurs. De cette expédition militaire ratée naîtront l\'égyptologie moderne et la pierre de Rosette.',
        ],

        [
            'type' => 'voyage',
            'voyage' => 'napoleon-1796',
            'leg' => 8,
            'chapter' => 'Le retour et le 18 brumaire',
            'location' => 'Paris',
            'year' => 1799,
            'title' => 'Il laisse son armée',
            'date_label' => 'août – novembre 1799',
            'story' => 'La flotte française a été détruite à Aboukir : l\'armée d\'Égypte est prisonnière de sa conquête. En août 1799, Bonaparte s\'embarque en secret, laisse ses hommes, et rentre. Six semaines après son débarquement à Fréjus, il renverse le Directoire.',
            'images' => ['Bonaparte 18 brumaire Saint-Cloud 1799', 'Bonaparte Premier Consul portrait'],
            'prefer' => 'painting',
            'script' => 'La flotte française a été détruite par Nelson à Aboukir : l\'armée d\'Égypte est prisonnière de sa propre conquête. En août 1799, Bonaparte monte secrètement à bord d\'une frégate et rentre en France, laissant ses hommes derrière lui. Suivez la dernière flèche. Il débarque à Fréjus le neuf octobre. Le neuf novembre — le dix-huit brumaire — il renverse le Directoire. La République existe encore sur le papier ; en réalité, la Révolution vient de trouver son maître.',
        ],

        [
            'type' => 'gallery',
            'chapter' => 'Du général à l\'empereur',
            'location' => 'Paris',
            'year' => 1804,
            'title' => 'Le sacre',
            'date_label' => '2 décembre 1804',
            'story' => 'Premier consul, puis consul à vie, puis empereur. Le 2 décembre 1804, à Notre-Dame, il prend la couronne des mains du pape et se couronne lui-même. Il conserve pourtant beaucoup de la Révolution : l\'égalité devant la loi, la fin des privilèges de naissance, le Code civil — qui sera repris dans une bonne partie de l\'Europe.',
            'images' => [
                'Le Sacre de Napoléon David 1804',
                'Code civil des Français 1804',
                'Napoléon Bonaparte portrait empereur',
            ],
            'prefer' => 'painting',
            'script' => 'Premier consul, puis consul à vie, puis empereur. Le deux décembre 1804, à Notre-Dame de Paris, il prend la couronne des mains du pape et se couronne lui-même. Ce geste dit tout : il ne tient son pouvoir de personne. Et pourtant il conserve une grande partie de la Révolution — l\'égalité devant la loi, la fin des privilèges de naissance, et le Code civil, qui sera repris dans une bonne partie de l\'Europe et qu\'on lit encore aujourd\'hui.',
        ],

        [
            'type' => 'story',
            'chapter' => 'Ce qui reste',
            'location' => 'France',
            'year' => 1815,
            'image' => 'Marianne République française symbole',
            'prefer' => 'painting',
            'script' => 'Napoléon tombe en 1815, et les rois reviennent. Mais on ne remet pas la France dans l\'état de 1788. Les privilèges de naissance ne reviendront pas. L\'idée que la souveraineté appartient à la nation, elle, ne repartira plus, ni en France ni ailleurs : elle voyagera avec les armées françaises dans toute l\'Europe. Liberté, égalité, fraternité : la devise est née dans ces années-là. Deux siècles plus tard, la France date encore sa fête nationale du jour où une foule a pris une prison presque vide.',
        ],

        [
            'type' => 'quiz',
            'when' => 'post',
            'chapter' => 'Ce que vous avez retenu',
            'questions' => [
                [
                    'q' => 'Pourquoi la prise de la Bastille compte-t-elle autant, alors qu\'il n\'y avait que sept prisonniers ?',
                    'o' => [
                        'Parce qu\'elle symbolisait le pouvoir d\'emprisonner sans jugement',
                        'Parce qu\'elle contenait le trésor royal',
                        'Parce que le roi y habitait',
                        'Parce qu\'elle protégeait Versailles',
                    ],
                    'c' => 0,
                    'e' => 'La lettre de cachet permettait d\'enfermer quelqu\'un sur simple ordre du roi.',
                ],
                [
                    'q' => 'Que décide le tiers état au Jeu de paume, le 20 juin 1789 ?',
                    'o' => [
                        'De ne pas se séparer avant d\'avoir donné une constitution à la France',
                        'De couronner Bonaparte',
                        'De déclarer la guerre à l\'Autriche',
                        'De quitter Versailles pour toujours',
                    ],
                    'c' => 0,
                    'e' => 'C\'est le moment où la souveraineté passe du roi à la nation.',
                ],
                [
                    'q' => 'Quelle est la principale arme de Bonaparte pendant la campagne d\'Italie ?',
                    'o' => ['Le nombre', 'La vitesse', 'La marine', 'L\'artillerie lourde'],
                    'c' => 1,
                    'e' => 'Il déplace une armée plus petite plus vite, et bat ses adversaires séparément.',
                ],
                [
                    'q' => 'Qu\'est-ce que le 18 brumaire ?',
                    'o' => [
                        'Le coup d\'État par lequel Bonaparte renverse le Directoire',
                        'Une bataille en Égypte',
                        'Le jour du sacre',
                        'Le vote de la Déclaration des droits de l\'homme',
                    ],
                    'c' => 0,
                    'e' => 'Le 9 novembre 1799, six semaines après son retour d\'Égypte.',
                ],
            ],
        ],
    ],
];
