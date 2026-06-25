<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pricing — landing page tiers
|--------------------------------------------------------------------------
| Edit these to change what shows on the public pricing section
| (resources/views/components/landing/pricing.blade.php). No Blade changes
| needed. Prices are the effective $/year for each commitment term, plus the
| total billed over the term. Keep `term` keys as quoted strings ('1','2','3')
| — they double as the Alpine selector values.
*/

return [

    // Default selected term on page load.
    'default_term' => '2',

    // Commitment lengths shown in the selector. `save` is the badge label
    // (null = no badge, e.g. the 1-year baseline).
    'terms' => [
        '1' => ['label' => '1 year',  'save' => null],
        '2' => ['label' => '2 years', 'save' => 'Save 15%'],
        '3' => ['label' => '3 years', 'save' => 'Save 25%'],
    ],

    // Product tiers (cards), left → right. Set `featured` => true on exactly
    // one tier to give it the amber "most popular" treatment. A tier with
    // `price_label` (instead of `prices`) shows custom copy across all terms.
    'tiers' => [

        [
            'name'     => 'Classroom',
            'tagline'  => 'For a single teacher or a small school just getting started.',
            'featured' => false,
            'cta'      => 'Start free trial',
            'href'     => '/login',
            'prices'   => [
                '1' => ['per_year' => '990', 'total' => '990'],
                '2' => ['per_year' => '840', 'total' => '1,680'],
                '3' => ['per_year' => '740', 'total' => '2,220'],
            ],
            'features' => [
                'Up to 1 classroom · 30 students',
                '4 AI-generated lessons per week',
                'Standard cinematic narration',
                'Full history lesson library',
                'Quizzes & student progress tracking',
                'Email support',
            ],
        ],

        [
            'name'     => 'School',
            'tagline'  => 'For a growing school running history across multiple classes.',
            'featured' => true,
            'badge'    => 'Most popular',
            'cta'      => 'Start free trial',
            'href'     => '/login',
            'prices'   => [
                '1' => ['per_year' => '4,490', 'total' => '4,490'],
                '2' => ['per_year' => '3,815', 'total' => '7,630'],
                '3' => ['per_year' => '3,370', 'total' => '10,110'],
            ],
            'features' => [
                'Up to 6 classrooms · 150 students',
                'Unlimited AI-generated lessons',
                'Premium ElevenLabs voices + HD scene art',
                'Animated historical avatars',
                'Teacher analytics dashboard',
                'Priority support',
            ],
        ],

        [
            'name'        => 'District',
            'tagline'     => 'For districts and large schools that need scale and control.',
            'featured'    => false,
            'cta'         => 'Contact sales',
            'href'        => 'mailto:hello@thelearningportal.us?subject=District%20pricing',
            'price_label' => "Let's talk",   // custom — same across every term
            'features'    => [
                'Unlimited classrooms & students',
                'Everything in School, plus:',
                'Single sign-on (SSO) & roster sync',
                'Custom historical avatars',
                'Dedicated success manager & onboarding',
                'Teacher PD & training sessions',
            ],
        ],

    ],

    // Fine print under the cards. Keep the auto-renewal disclosure clear and
    // conspicuous — required for negative-option billing (FTC) and EU/UK
    // consumer law, especially for public-funded school buyers.
    'fine_print' => 'All plans include a 14-day free trial — no credit card required. '
        .'Plans automatically renew for one additional year at the then-current rate when your term ends; '
        .'we email you 30 days before renewal and you can cancel anytime from your account.',

];
