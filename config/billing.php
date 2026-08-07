<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Narration credits
    |--------------------------------------------------------------------------
    |
    | The one thing a teacher can buy. Editing a lesson script has the scene read
    | aloud again, which ElevenLabs bills by the character; every lesson includes
    | some editing for free (App\Support\NarrationBudget) and credits cover the
    | rest, across any of the teacher's lessons.
    |
    | Read these through App\Support\NarrationCreditPack, never directly.
    |
    */

    'credit_pack' => [

        /** Characters granted per purchase. */
        'characters' => (int) env('NARRATION_CREDIT_CHARACTERS', 5000),

        /**
         * What WE KEEP, in integer cents, EXCLUSIVE OF VAT (Bart's decision,
         * 2026-08-06).
         *
         * VAT at the buyer's own country rate is added on top at checkout, so
         * the 5.00 is net however much tax applies: a Dutch teacher pays 6.05, a
         * German 5.95, an Italian 6.10. A school with a valid VAT number is
         * reverse-charged and pays exactly 5.00.
         *
         * Because the price is exclusive, every consumer-facing screen must lead
         * with the GROSS figure — EU price-indication rules require the total a
         * consumer actually pays to be the prominent one. Use
         * NarrationCreditPack::grossLabel(), not priceLabel().
         *
         * Stripe Tax computes the real rate per country and reports the split
         * back, which is what gets stored on the purchase row.
         */
        'amount_cents' => (int) env('NARRATION_CREDIT_AMOUNT_CENTS', 500),

        /**
         * The VAT rate shown on the buy screen before Stripe has calculated the
         * real one. 21%, the Dutch GENERAL rate.
         *
         * We briefly ran this at the 9% reduced rate for educational content and
         * parked that (Bart, 2026-08-07): the reduced rate turns on a
         * classification nobody has confirmed, and the safe direction to be wrong
         * in is the higher one. Charging 21% and later learning we could have
         * charged 9% costs a teacher 60 cents; charging 9% and later learning we
         * owed 21% is a bill we cannot go back and collect.
         *
         * Must stay in step with the tax code below — the code is what Stripe
         * actually charges from, so a mismatch means the button quotes a price
         * the checkout does not.
         *
         * Display only. What is charged and stored comes from Stripe Tax, which
         * works it out from the tax code and the buyer's address.
         */
        'display_vat_rate' => (float) env('NARRATION_CREDIT_VAT_RATE', 0.21),

        'currency' => env('NARRATION_CREDIT_CURRENCY', 'eur'),

        /*
         * What Stripe Tax thinks we are selling. This decides the VAT rate.
         *
         * Stripe REQUIRES a tax code on the line item (Managed Payments refuses a session without
         * one), so this is not optional, and the default here is a real tax determination rather
         * than a placeholder. WORTH CONFIRMING WITH AN ACCOUNTANT alongside the expiry question.
         *
         * txcd_10000000, "General - Electronically Supplied Services": the teacher is buying
         * capacity inside our authoring tool, delivered electronically. General rate, 21% in the
         * Netherlands.
         *
         * PARKED, NOT SETTLED (Bart, 2026-08-07). We tried txcd_20060258, "On demand Online
         * Courses", which carries the educational classification and the 9% reduced rate, and
         * reverted to this one deliberately: the reduced rate depends on a classification no
         * accountant has confirmed, and what a teacher actually buys here is authoring capacity
         * rather than a course delivered to a learner. Overcharging is recoverable; undercharging
         * is a bill that cannot be collected after the fact.
         *
         * Worth revisiting WITH ADVICE. In the Netherlands education is often VAT-EXEMPT rather
         * than reduced-rate, which is a third possible answer and a better one than either of
         * these. Reduced rates and exemptions also differ per EU country. Change this and
         * display_vat_rate above together — they must agree.
         *
         * Deliberately NOT one of the education codes. txcd_20060052 (Educational Services) is for
         * academic classes run by an education establishment, and the online-course codes
         * (txcd_20060158 and friends) are for selling pre-recorded instruction. We sell neither: we
         * sell the tool a teacher makes their own lesson with. Claiming an education code we do not
         * qualify for would understate VAT in countries that treat education favourably, and the
         * bill for that lands on us, not on the teacher.
         *
         * Alternatives if that turns out to be wrong: txcd_10103000 (SaaS, personal use) and
         * txcd_10103001 (SaaS, business use). Stripe takes one code per line item, so a single
         * consumer-leaning code is the practical choice while individual teachers are most buyers.
         */
        'tax_code' => env('NARRATION_CREDIT_TAX_CODE', 'txcd_10000000'),

        /**
         * How long bought credit lasts, in days from the moment it was bought.
         *
         * Configurable rather than hardcoded because the right number here is a
         * legal question, not an engineering one: prepaid credit that expires is
         * regulated in several EU countries and 30 days is short by the standards
         * of those rules. Changing it is an env edit, not a migration.
         *
         * Set to 0 to switch expiry off entirely, in which case credit lasts
         * forever and nothing a teacher paid for can vanish.
         */
        'expires_after_days' => (int) env('NARRATION_CREDIT_EXPIRY_DAYS', 30),
    ],

];
