<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra fields mcamara/laravel-localization expects per locale, so a language
 * can be added from the admin panel with no config file changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('short_name', 8)->nullable()->after('native_name'); // ქარ, ENG — the header switcher
            $table->string('regional', 16)->nullable()->after('short_name');   // ka_GE, en_GB
            $table->string('script', 8)->default('Latn')->after('regional');   // Geor, Latn, Cyrl
        });
    }

    public function down(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn(['short_name', 'regional', 'script']);
        });
    }
};
