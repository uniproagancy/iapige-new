<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Languages are data, not config: the admin panel adds, activates and orders
 * them. Every *_translations table points at languages.code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();          // ka, en, ru — also the URL / app()->getLocale() value
            $table->string('name');                         // Georgian, English
            $table->string('native_name');                  // ქართული, English
            $table->string('flag', 16)->nullable();         // emoji or icon key
            $table->boolean('is_default')->default(false);  // exactly one — enforced in App\Models\Language
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
