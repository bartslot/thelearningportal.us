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
