<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StrategyGame;
use Illuminate\Database\Seeder;

class StrategyGameSeeder extends Seeder
{
    public function run(): void
    {
        $games = [
            [
                'slug'             => 'napoleon-waterloo-1815',
                'title'            => 'The Last Gamble: Napoleon at Waterloo',
                'description'      => 'June 1815. Napoleon has returned from exile and commands the French Army. Wellington holds the ridge at Waterloo with Anglo-Dutch forces. Blücher\'s Prussian army is closing in. Teams take the role of Napoleon\'s war council: with paper and pen, map a strategy that could win the day — or avoid the catastrophe that ended an empire.',
                'subject'          => 'History',
                'topic_keywords'   => ['french revolution', 'napoleon', 'waterloo', 'war', 'empire', 'france', '1815', 'battle', 'strategy', 'wellington'],
                'grade_min'        => 8,
                'grade_max'        => 12,
                'team_size_min'    => 3,
                'team_size_max'    => 5,
                'duration_minutes' => 10,
                'instructions'     => "Each team is Napoleon's war council on the morning of 18 June 1815.\n\nYour mission: design a strategy that defeats Wellington before the Prussians arrive.\n\n**On paper, your team must answer:**\n1. Which corps attacks first, and where on the ridge?\n2. How do you prevent the Prussians from reaching the battlefield in time?\n3. What is your reserve plan if the first assault fails?\n4. Draw a rough battle map showing your troop movements.\n\n**You have 10 minutes.** Then each team presents their strategy — and we reveal what actually happened.",
                'materials'        => ['Paper', 'Pen or pencil', 'Optional: ruler for drawing lines', 'Optional: coloured markers for troop lines'],
            ],
            [
                'slug'             => 'julius-caesar-rubicon',
                'title'            => 'Cross the Rubicon',
                'description'      => '49 BC. Caesar stands at the Rubicon river with his Legio XIII. Roman law forbids a general from crossing with his army. Pompey and the Senate are in Rome. Teams must decide: cross and risk civil war, or return to Rome unarmed and risk execution?',
                'subject'          => 'History',
                'topic_keywords'   => ['roman', 'julius caesar', 'rubicon', 'republic', 'senate', 'pompey', 'civil war', 'rome', 'ancient'],
                'grade_min'        => 7,
                'grade_max'        => 11,
                'team_size_min'    => 3,
                'team_size_max'    => 5,
                'duration_minutes' => 10,
                'instructions'     => "Your team is Caesar's inner council standing at the Rubicon.\n\n**On paper, your team must answer:**\n1. What are the three strongest arguments FOR crossing?\n2. What are the three strongest arguments AGAINST?\n3. If you cross — what is your first move in Rome?\n4. If you don't cross — how do you negotiate your safety with the Senate?\n\nPresent your recommendation and reasoning.",
                'materials'        => ['Paper', 'Pen or pencil'],
            ],
            [
                'slug'             => 'ww1-trench-stalemate',
                'title'            => 'Break the Stalemate: Western Front 1917',
                'description'      => '1917. Both sides have been trapped in trenches for three years. Millions are dead. Teams are Allied High Command: design a new strategy that breaks the German lines without another Somme-scale catastrophe.',
                'subject'          => 'History',
                'topic_keywords'   => ['world war', 'ww1', 'trench', 'western front', 'somme', 'verdun', 'allied', 'germany', '1917', 'stalemate'],
                'grade_min'        => 8,
                'grade_max'        => 12,
                'team_size_min'    => 3,
                'team_size_max'    => 6,
                'duration_minutes' => 10,
                'instructions'     => "It is 1917. You are Allied High Command.\n\n**Three options are on the table. Teams must evaluate each:**\n1. Another massive frontal assault — but this time with new tank support\n2. An economic blockade strategy — starve Germany into surrender\n3. A diplomatic offensive — try to separate Austria-Hungary from Germany\n\n**On paper: rank these options, explain why, and propose one creative alternative.**",
                'materials'        => ['Paper', 'Pen or pencil'],
            ],
            [
                'slug'             => 'cold-war-cuban-missiles',
                'title'            => 'Thirteen Days: The Cuban Missile Crisis',
                'description'      => 'October 1962. US spy planes discover Soviet nuclear missiles in Cuba. Teams take the role of Kennedy\'s ExComm — you have 13 days to defuse the crisis without starting World War III.',
                'subject'          => 'History',
                'topic_keywords'   => ['cold war', 'cuban', 'missile', 'kennedy', 'soviet', 'nuclear', '1962', 'khrushchev', 'crisis', 'cuba'],
                'grade_min'        => 9,
                'grade_max'        => 12,
                'team_size_min'    => 3,
                'team_size_max'    => 6,
                'duration_minutes' => 10,
                'instructions'     => "You are Kennedy's ExComm. Soviet missiles in Cuba are 13 minutes from US cities.\n\n**Your team must choose and defend one of these options:**\n- **Air strike** — destroy the missiles before they are armed\n- **Naval blockade** — stop Soviet ships bringing more weapons\n- **Diplomatic back-channel** — negotiate secretly with Khrushchev\n- **Do nothing** — call the Soviet bluff\n\n**On paper: choose your strategy, list the three biggest risks, and state your red line for military action.**",
                'materials'        => ['Paper', 'Pen or pencil'],
            ],
            [
                'slug'             => 'haitian-revolution',
                'title'            => 'The First Free Nation: Haitian Revolution 1791',
                'description'      => '1791. Saint-Domingue is the most profitable colony in the world. Half a million enslaved people rise up against French colonial rule. Teams are the revolutionary council: how do you win freedom against the world\'s greatest military power?',
                'subject'          => 'History',
                'topic_keywords'   => ['haiti', 'revolution', 'toussaint', 'slavery', 'freedom', 'caribbean', 'france', 'colonial', '1791', 'independence'],
                'grade_min'        => 8,
                'grade_max'        => 12,
                'team_size_min'    => 3,
                'team_size_max'    => 5,
                'duration_minutes' => 10,
                'instructions'     => "You are the revolutionary council of Saint-Domingue, 1791.\n\n**Your challenges:**\n1. You have numbers but the French have cannons, ships and professional soldiers\n2. Spain and Britain also want the island\n3. Disease kills French soldiers — how do you use this?\n4. You need to build alliances — who do you trust?\n\n**On paper: design your 3-part strategy for independence. Include: military tactics, international alliances, and your vision for the new nation.**",
                'materials'        => ['Paper', 'Pen or pencil'],
            ],
            [
                'slug'             => 'renaissance-florence-medici',
                'title'            => 'The Medici Gamble: Florence 1434',
                'description'      => 'Cosimo de\' Medici has returned from exile and wants to make Florence the greatest city in Europe. Teams are his council of advisors: how do you spend the family fortune to create a golden age?',
                'subject'          => 'History',
                'topic_keywords'   => ['medici', 'renaissance', 'florence', 'italy', 'art', 'banking', 'cosimo', '1434', 'patron', 'humanities'],
                'grade_min'        => 7,
                'grade_max'        => 10,
                'team_size_min'    => 2,
                'team_size_max'    => 4,
                'duration_minutes' => 10,
                'instructions'     => "You have 100,000 florins and 10 years. You are Cosimo de' Medici's council.\n\n**Spend your budget across these categories (total must equal 100,000):**\n- Arts & architecture (building the Duomo, commissioning paintings)\n- Scholarships & libraries (bringing scholars to Florence)\n- Trade & banking (expanding the family business empire)\n- Political alliances (marriages, diplomacy, bribery)\n- Military defence (protecting Florence from rival city-states)\n\n**On paper: show your budget allocation and justify each choice.**",
                'materials'        => ['Paper', 'Pen or pencil'],
            ],
        ];

        foreach ($games as $game) {
            StrategyGame::updateOrCreate(['slug' => $game['slug']], $game);
        }
    }
}
