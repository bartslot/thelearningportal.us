<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's one Stripe Customer, reused for every purchase they ever make.
 *
 * Before this, checkout passed customer_creation=always and a teacher who bought three packs became
 * three unrelated Customers in Stripe. That is untidy for support and fatal for anything beyond a
 * one-off payment: an invoice and a subscription both hang off a Customer, and a school split
 * across three of them cannot be billed or given a receipt history.
 *
 * `livemode` is stored next to the id because a test-mode `cus_...` and a live-mode one look
 * identical and neither works with the other's key. Without it, the day the live keys go in, every
 * returning teacher's checkout would fail with "No such customer" — at exactly the moment nobody
 * wants to be debugging. With it we simply notice the mismatch and make a fresh Customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('is_guest_demo');
            $table->boolean('stripe_customer_livemode')->nullable()->after('stripe_customer_id');

            $table->index('stripe_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropColumn(['stripe_customer_id', 'stripe_customer_livemode']);
        });
    }
};
