<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Meta needs to tie a phone call back to the advert that caused it.
 *
 * Leaving a number is a Lead, and an operator rings back and takes the order
 * by hand. Those two are the same person and the same journey, but the order
 * is written minutes later from the admin — a different request, a different
 * machine, no cookies — so the Purchase carried none of the identifiers that
 * would let Meta recognise them. The campaign got the Lead and never the sale
 * it turned into.
 *
 * The same shape order_pixel_data already uses, captured at the moment the
 * number is left, which is the only moment the browser is there to ask.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('callback_requests', function (Blueprint $table) {
            // the id the browser's own Lead carried, so the two are one event
            $table->string('lead_event_id', 64)->nullable()->after('note');

            $table->string('fbp')->nullable()->after('lead_event_id');
            $table->string('fbc')->nullable()->after('fbp');
            $table->string('ip', 45)->nullable()->after('fbc');
            $table->text('user_agent')->nullable()->after('ip');
            $table->string('source_url', 500)->nullable()->after('user_agent');

            // the cookie banner's answer when the number was left
            $table->boolean('consented')->default(false)->after('source_url');

            // the order the operator wrote from this call, once there is one
            $table->foreignId('order_id')->nullable()->after('consented')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('callback_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn([
                'lead_event_id', 'fbp', 'fbc', 'ip', 'user_agent', 'source_url', 'consented',
            ]);
        });
    }
};
