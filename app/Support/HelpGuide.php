<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One source of truth for "how does this app work?" copy.
 *
 * Two surfaces read from here, so they can never drift apart:
 *   • the click-through welcome tour a new account sees once (App\Livewire\Onboarding\WelcomeTour)
 *   • the help centre a teacher can re-read any time (App\Livewire\Help, /help)
 *
 * A tour step is the one-minute version of a help topic and links to it by `topic` id.
 * All copy goes through __() so lang/nl.json can translate it for the Dutch pilot.
 */
final class HelpGuide
{
    /**
     * Help-centre topics, in reading order. Each topic renders as one section on /help and is
     * linkable as /help#{id}.
     *
     * @return list<array{id:string,title:string,icon:string,summary:string,
     *     sections:list<array{heading:string,body:?string,steps:list<string>}>,
     *     link:?array{label:string,route:string}}>
     */
    public static function topics(): array
    {
        return [
            [
                'id' => 'overview',
                'title' => __('What The Learning Portal does'),
                'icon' => 'academic-cap',
                'summary' => __('You describe a topic, the portal builds a narrated story lesson your class can play on any device.'),
                'sections' => [
                    [
                        'heading' => __('The short version'),
                        'image' => 'dashboard',
                        'body' => __('A lesson is a series of scenes. Scenes are building blocks for a lesson. Each building block can have a story with a narrator who reads out loud, a map, a game or a quiz question. You stay in charge: nothing reaches your students until you publish it.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('From idea to classroom'),
                        'body' => null,
                        'steps' => [
                            __('Describe what you want to teach, and for who.'),
                            __('The portal writes the story, finds historical images and records the narration.'),
                            __('You edit anything you want to change.'),
                            __('You publish, and share a six-character lesson code with your class.'),
                        ],
                    ],
                ],
                'link' => null,
            ],
            [
                'id' => 'create',
                'title' => __('Create your first lesson'),
                'icon' => 'sparkles',
                'summary' => __('Two ways in: tell the portal your goal in plain language, or fill in the wizard yourself.'),
                'sections' => [
                    [
                        'heading' => __('The fastest way: New lesson'),
                        'image' => 'new-lesson',
                        'body' => __('Press New lesson on the dashboard and type what you want, for example: "a lesson about the Dutch Revolt for grade 7, about 20 minutes". The portal turns that into settings you can adjust with one click before it starts building.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('The five steps of the wizard'),
                        'image' => 'wizard-steps',
                        'body' => __('However you start, a lesson always moves through the same five steps. You can jump back to an earlier step at any time.'),
                        'steps' => [
                            __('Settings: topic, age group, length and the narrator who tells the story.'),
                            __('Story: the shape of the lesson, a story with a quiz, a story game, or a short recap.'),
                            __('Generate: the portal writes the scenes, sources the images and records the audio. This runs in the background, so you can close the tab.'),
                            __('Configure: the editor, where you change anything you like.'),
                            __('Play: watch the lesson exactly as your students will see it.'),
                        ],
                    ],
                    [
                        'heading' => __('How long does it take?'),
                        'body' => __('Generating a lesson usually takes a few minutes. The progress bar keeps counting while you do something else, and the lesson shows up on your Lessons page when it is done.'),
                        'steps' => [],
                    ],
                ],
                'link' => ['label' => __('Start a lesson'), 'route' => 'teacher.lessons.chat'],
            ],
            [
                'id' => 'edit',
                'title' => __('Edit the lesson'),
                'icon' => 'pencil',
                'summary' => __('The Configure step is a full-screen editor: pick a scene on the left, change it on the right.'),
                'sections' => [
                    [
                        'heading' => __('Scenes'),
                        'image' => 'wizard-configure',
                        'body' => __('The rail on the left lists every scene in order. Click one to open it. You can reorder scenes, delete the ones you do not need, and add new ones.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('What a scene can be'),
                        'body' => null,
                        'steps' => [
                            __('A narrated picture, the backbone of most lessons.'),
                            __('A gallery of paintings or photographs to look at together.'),
                            __('A map, for example a voyage that draws itself while the narrator speaks.'),
                            __('A game or a choice moment where teams steer the story.'),
                            __('A quiz question to check whether the class is following.'),
                        ],
                    ],
                    [
                        // Where the voyage editor's own instructions live now. They used to sit in the
                        // waypoint panel, above the fields a teacher opens dozens of times per lesson —
                        // read once, then permanent clutter. Here they are one click away and out of
                        // the way. See the Help button beside Format in the editor toolbar.
                        'heading' => __('Drawing a voyage route'),
                        'body' => __('A voyage scene is one crossing: the ship sails from the stop before it to this one.'),
                        'steps' => [
                            __('Drag the amber Destination X to set where this crossing ends.'),
                            __('Hover the blue sailing line and drag to bend the route around a coast, or drag an existing bend to move it.'),
                            __('Double-click a bend to remove it.'),
                            __('Travelled by picks the marker for this crossing only — it swaps as the traveller reaches the coast. No model to suit the journey? Use a picture instead.'),
                            __('Gallery / description is what opens when the class arrives: the story of the stop and its images.'),
                        ],
                    ],
                    [
                        'heading' => __('Text, images and narration'),
                        'body' => __('Rewrite the narration text and the narrator records it again in your chosen voice. Swap an image for another one from the museum collections, or drop text and drawings on top of a picture. Every change saves itself.'),
                        'steps' => [],
                    ],
                ],
                'link' => null,
            ],
            [
                'id' => 'publish',
                'title' => __('Preview and publish'),
                'icon' => 'play',
                'summary' => __('Watch the lesson end to end, then publish it. Students only ever see published lessons.'),
                'sections' => [
                    [
                        'heading' => __('Preview first'),
                        'image' => 'wizard-play',
                        'body' => __('The Play step runs the lesson exactly as a student sees it, narration and all. Spot something? Go back to Configure, fix it, and play it again.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('Publishing'),
                        'body' => __('The Publish button sits in the top-right corner of the Configure step. Every scene has to be finished before a lesson can go out, so if publishing is blocked the portal names the scenes that still need attention.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('Changing your mind'),
                        'body' => __('A published lesson can still be edited. Your class always plays the current version, so a fix reaches them straight away.'),
                        'steps' => [],
                    ],
                ],
                'link' => null,
            ],
            [
                'id' => 'share',
                'title' => __('Share a lesson with your class'),
                'icon' => 'megaphone',
                'summary' => __('Every lesson has a six-character code. Students open it on a laptop, tablet or phone. No account needed.'),
                'sections' => [
                    [
                        'heading' => __('The lesson code'),
                        'image' => 'lessons',
                        'body' => __('Write the code on the board, or share the link. Students go to the lesson page, and the lesson starts. There is nothing to install and nothing to sign up for.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('Playing it together'),
                        'body' => __('You can also play a lesson on the big screen and let the class answer the quiz on their own devices. Scores appear on the leaderboard as they come in.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('On paper'),
                        'body' => __('Prefer paper? Every lesson prints as a handout, and a story game prints as a full game pack with role cards for teams.'),
                        'steps' => [],
                    ],
                ],
                'link' => ['label' => __('Go to your lessons'), 'route' => 'teacher.lessons.index'],
            ],
            [
                'id' => 'classes',
                'title' => __('Classes and students'),
                'icon' => 'users',
                'summary' => __('Group your students in a class so results land in the right place. Students do not need accounts.'),
                'sections' => [
                    [
                        'heading' => __('Setting up a class'),
                        'image' => 'classes',
                        'body' => __('Create a class, give it a name and a grade level, and add your students by name. That roster is enough for the portal to match results to the right child.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('The join code'),
                        'body' => __('Each class has its own join code. Students who enter it are recognised as part of that class, so their results are collected together.'),
                        'steps' => [],
                    ],
                ],
                'link' => ['label' => __('Manage classes'), 'route' => 'teacher.classes.index'],
            ],
            [
                'id' => 'results',
                'title' => __('Results and what they tell you'),
                'icon' => 'chart-bar',
                'summary' => __('See how the class did, per lesson and per question, and which children need a second pass.'),
                'sections' => [
                    [
                        'heading' => __('Per lesson'),
                        'image' => 'results',
                        'body' => __('Every lesson report shows the average score, who took part, and how each question was answered. A question everyone got wrong usually says more about the question than about the class.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('Across your classes'),
                        'body' => __('The Results page gathers the last thirty days: average scores, how much was played, and the children scoring below half who could use extra attention.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('Answers on paper'),
                        'body' => __('If your class answered on paper, print the answer sheet and photograph the completed sheets. The portal reads them and adds the scores to the same report.'),
                        'steps' => [],
                    ],
                ],
                'link' => ['label' => __('Open results'), 'route' => 'teacher.results.hub'],
            ],
            [
                'id' => 'timemap',
                'title' => __('The Time-Map'),
                'icon' => 'building-library',
                'summary' => __('A map of the world through history. Drag the year and watch the borders move.'),
                'sections' => [
                    [
                        'heading' => __('What it is for'),
                        'image' => 'timemap',
                        'body' => __('Use it to show where a story takes place, and who the neighbours were at the time. Click a territory to read a short summary, or have it read aloud to the class.'),
                        'steps' => [],
                    ],
                ],
                'link' => ['label' => __('Open the Time-Map'), 'route' => 'teacher.timemap'],
            ],
            [
                'id' => 'account',
                'title' => __('Your account'),
                'icon' => 'user-circle',
                'summary' => __('Switch the interface between English and Dutch, and find this guide again whenever you need it.'),
                'sections' => [
                    [
                        'heading' => __('Language'),
                        'image' => 'settings',
                        'body' => __('Account settings holds the language switch. It changes the interface only, not the language your lessons are written in.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('Finding help again'),
                        'body' => __('The question mark in the top bar brings you back to this page from anywhere. You can also replay the welcome tour from the button at the top of this page.'),
                        'steps' => [],
                    ],
                ],
                'link' => ['label' => __('Account settings'), 'route' => 'settings.index'],
            ],

            // ── Admins only ─────────────────────────────────────────────────
            // An admin is a teacher with extra keys, appointed per school. These topics are hidden
            // from ordinary teachers; see `audience` and Help::topics().
            [
                'id' => 'admin-role',
                'audience' => 'admin',
                'title' => __('Being an admin'),
                'icon' => 'academic-cap',
                'summary' => __('You are a teacher with extra keys. The role is appointed per school.'),
                'sections' => [
                    [
                        'heading' => __('What is different'),
                        'body' => __('Everything a teacher can do, you can do. On top of that you look after what the whole school shares: the narrators your colleagues pick from, and the story catalogue their lessons are built out of.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('Where the extra pages are'),
                        'body' => __('Your top bar carries two entries ordinary teachers do not see.'),
                        'steps' => [
                            __('Dashboard: the admin overview, with the narrator count and a way into every lesson.'),
                            __('Narrator Studio: the narrators teachers can choose in the wizard.'),
                        ],
                    ],
                    [
                        'heading' => __('A word of care'),
                        'body' => __('What you change here lands in your colleagues\' lesson wizard straight away. Turning a narrator off removes it from their picker, so tell them before you do.'),
                        'steps' => [],
                    ],
                ],
                'link' => ['label' => __('Open the admin dashboard'), 'route' => 'admin.dashboard'],
            ],
            [
                'id' => 'admin-avatars',
                'audience' => 'admin',
                'title' => __('The Narrator Studio'),
                'icon' => 'user-circle',
                'summary' => __('The historical figures who tell the stories: their portrait, their voice, and whether teachers can pick them.'),
                'sections' => [
                    [
                        'heading' => __('What the studio is for'),
                        'body' => __('Every narrator is a portrait plus a voice. The studio is where that pairing is set up and listened to before a class ever hears it.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('Turning a narrator on or off'),
                        'body' => __('An inactive narrator disappears from the wizard, so no new lesson can pick it. Lessons that already use it keep playing exactly as they were.'),
                        'steps' => [],
                    ],
                ],
                'link' => ['label' => __('Open the Narrator Studio'), 'route' => 'admin.avatars.index'],
            ],
            [
                'id' => 'admin-stories',
                'audience' => 'admin',
                'title' => __('The story review queue'),
                'icon' => 'book-open',
                'summary' => __('Source material moves draft, reviewed, published. Nothing reaches a classroom without a person saying yes.'),
                'sections' => [
                    [
                        'heading' => __('Draft, reviewed, published'),
                        'body' => __('A story is the material a lesson gets built from. It arrives as a draft, you read it, and publishing is what makes it available to teachers in the wizard.'),
                        'steps' => [],
                    ],
                    [
                        'heading' => __('What to check before publishing'),
                        'body' => null,
                        'steps' => [
                            __('Do the learning objectives match what your school actually teaches?'),
                            __('Are the sources ones you would put in front of a class?'),
                            __('Is the era right? A wrong century sends the images and the map astray.'),
                        ],
                    ],
                ],
                'link' => ['label' => __('Open the review queue'), 'route' => 'admin.stories.review'],
            ],
        ];
    }

    /**
     * Places in the app the help search can send a teacher straight to. Searching help for "class"
     * should not only explain classes, it should offer the button that opens them.
     *
     * `keywords` are extra words a teacher might search for that do not appear in the label, so
     * "grades" or "marks" still finds Results.
     *
     * @return list<array{label:string,description:string,route:string,icon:string,keywords:string}>
     */
    public static function shortcuts(): array
    {
        return [
            [
                'label' => __('New lesson'),
                'description' => __('Describe what you want to teach and let the portal build it.'),
                'route' => 'teacher.lessons.chat',
                'icon' => 'sparkles',
                'keywords' => __('create make build generate story topic'),
            ],
            [
                'label' => __('Lessons'),
                'description' => __('Everything you have made, with its lesson code and status.'),
                'route' => 'teacher.lessons.index',
                'icon' => 'book-open',
                'keywords' => __('code share publish draft library'),
            ],
            [
                'label' => __('Classes'),
                'description' => __('Class rosters and join codes.'),
                'route' => 'teacher.classes.index',
                'icon' => 'users',
                'keywords' => __('students roster group join code pupils'),
            ],
            [
                'label' => __('Results'),
                'description' => __('Quiz scores per lesson, per question and per child.'),
                'route' => 'teacher.results.hub',
                'icon' => 'chart-bar',
                'keywords' => __('scores marks grades analytics report answers'),
            ],
            [
                'label' => __('Time-Map'),
                'description' => __('The history map, to show where a story takes place.'),
                'route' => 'teacher.timemap',
                'icon' => 'building-library',
                'keywords' => __('map atlas borders year world geography'),
            ],
            [
                'label' => __('Narrator Studio'),
                'description' => __('The narrators teachers can pick, their portraits and voices.'),
                'route' => 'admin.avatars.index',
                'icon' => 'user-circle',
                'keywords' => __('narrator voice portrait avatar figure admin'),
                'audience' => 'admin',
            ],
            [
                'label' => __('Story review'),
                'description' => __('Approve source material before teachers can build lessons from it.'),
                'route' => 'admin.stories.review',
                'icon' => 'book-open',
                'keywords' => __('review approve publish draft catalogue source admin'),
                'audience' => 'admin',
            ],
            [
                'label' => __('Account settings'),
                'description' => __('Your language and account details.'),
                'route' => 'settings.index',
                'icon' => 'user-circle',
                'keywords' => __('language dutch german french italian english profile'),
            ],
        ];
    }

    /**
     * The click-through welcome tour.
     *
     * `anchor` is the `data-tour` value of the element the step spotlights; null makes it a centred
     * card. An anchor that is not on the page (collapsed nav, a different screen size) also falls
     * back to a centred card rather than pointing at nothing — see resources/js/onboarding/tour.js.
     * `placement` is the preferred side; the positioner flips it when there is no room.
     *
     * `topic` deep-links to the fuller explanation on /help, and `cta` is the one thing worth doing
     * after the tour closes.
     *
     * @return list<array{key:string,title:string,body:string,bullets:list<string>,icon:string,
     *     anchor:?string,placement:string,topic:?string,cta:?array{label:string,route:string}}>
     */
    public static function tour(): array
    {
        return [
            [
                'key' => 'welcome',
                'anchor' => null,
                'placement' => 'center',
                'title' => __('Welcome to The Learning Portal'),
                'body' => __('In about a minute, here is everything you need to build your first lesson. You can leave at any point and pick this up again later.'),
                'bullets' => [],
                'icon' => 'academic-cap',
                'topic' => 'overview',
                'cta' => null,
            ],
            [
                'key' => 'create',
                'anchor' => 'new-lesson',
                'placement' => 'bottom',
                'title' => __('Describe a lesson, get a lesson'),
                'body' => __('Press New lesson and type what you want to teach, the way you would say it out loud. The portal writes the story, finds historical images and records the narration.'),
                'bullets' => [
                    __('Say the topic, the age group and roughly how long.'),
                    __('Adjust the suggestions with one click.'),
                    __('Building takes a few minutes and runs in the background.'),
                ],
                'icon' => 'sparkles',
                'topic' => 'create',
                'cta' => null,
            ],
            [
                'key' => 'edit',
                'anchor' => null,
                'placement' => 'center',
                'title' => __('Make it yours'),
                'body' => __('The Configure step is the editor. Pick a scene on the left, change it on the right. Nothing is fixed: rewrite the text and the narrator reads your version instead.'),
                'bullets' => [
                    __('Reorder, add or delete scenes.'),
                    __('Swap an image for another from the museum collections.'),
                    __('Add a map, a game or a quiz question.'),
                ],
                'icon' => 'pencil',
                'topic' => 'edit',
                'cta' => null,
            ],
            [
                'key' => 'publish',
                'anchor' => null,
                'placement' => 'center',
                'title' => __('Watch it, then publish it'),
                'body' => __('The Play step shows the lesson exactly as your class will see it. Happy with it? Publish puts it live. Until then, no student can open it.'),
                'bullets' => [],
                'icon' => 'play',
                'topic' => 'publish',
                'cta' => null,
            ],
            [
                'key' => 'share',
                'anchor' => 'nav-lessons',
                'placement' => 'bottom',
                'title' => __('Your class only needs a code'),
                'body' => __('Every published lesson has a six-character code. Write it on the board and your students are in, on whatever device they have. No accounts, no installs.'),
                'bullets' => [
                    __('Play it on the big screen and let them answer along.'),
                    __('Or print the handout and do it on paper.'),
                ],
                'icon' => 'megaphone',
                'topic' => 'share',
                'cta' => null,
            ],
            [
                'key' => 'results',
                'anchor' => 'nav-results',
                'placement' => 'bottom',
                'title' => __('See how it went'),
                'body' => __('Group your students into a class and their quiz results come back per lesson and per question, so you can see what stuck and what needs a second pass.'),
                'bullets' => [],
                'icon' => 'chart-bar',
                'topic' => 'results',
                'cta' => null,
            ],
            [
                'key' => 'done',
                'anchor' => 'help',
                'placement' => 'bottom',
                'title' => __('That is all of it'),
                'body' => __('The question mark in the top bar opens the help centre whenever you want to read any of this again, in more detail.'),
                'bullets' => [],
                'icon' => 'check-circle',
                'topic' => null,
                'cta' => ['label' => __('Create my first lesson'), 'route' => 'teacher.lessons.chat'],
            ],
        ];
    }
}
