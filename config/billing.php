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
         * What the teacher pays, in integer cents, INCLUSIVE OF VAT.
         *
         * The 5.00 euro is the whole of it: the VAT owed at the customer's own
         * country rate comes out of this, not on top. About 0.87 at the Dutch
         * 21%, netting about 4.13; a German teacher is 19% and an Italian 22%,
         * so what we keep varies by country while the price on the button does
         * not. That is what EU price-indication rules expect of a price shown to
         * a consumer, and nearly every buyer here is an individual teacher.
         *
         * Stripe Tax works the rate out per country and reports the split back,
         * which is what gets stored on the purchase row. A school entering a
         * valid VAT number is reverse-charged and the whole 5.00 is net.
         */
        'amount_cents' => (int) env('NARRATION_CREDIT_AMOUNT_CENTS', 500),

        'currency' => env('NARRATION_CREDIT_CURRENCY', 'eur'),

        /*
         * What Stripe Tax thinks we are selling. This decides the VAT rate.
         *
         * Stripe REQUIRES a tax code on the line item (Managed Payments refuses a session without
         * one), so this is not optional, and the default here is a real tax determination rather
         * than a placeholder. WORTH CONFIRMING WITH AN ACCOUNTANT alongside the expiry question.
         *
         * txcd_10000000, "General - Electronically Supplied Services", is the honest description:
         * the teacher is buying capacity inside our authoring tool, delivered electronically. It
         * maps onto the EU's own "electronically supplied services" category, which is what the
         * OSS rules are written around.
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
