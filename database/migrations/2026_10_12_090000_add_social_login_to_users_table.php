<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing in with Google or Facebook.
 *
 * Two columns and one loosened constraint:
 *
 * provider / provider_id identify the account at the other end, because an
 * email address is not enough on its own — people change them, and matching
 * on email alone is how one person's account becomes another's.
 *
 * password becomes nullable, because somebody who has only ever signed in
 * with Google has none. It was NOT NULL, so such a user could not be created
 * at all without inventing a password nobody would ever know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('password');
            $table->string('provider_id')->nullable()->after('provider');
            $table->string('avatar')->nullable()->after('provider_id');

            $table->unique(['provider', 'provider_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);
            $table->dropColumn(['provider', 'provider_id', 'avatar']);
        });
    }
};
