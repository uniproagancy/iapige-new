<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google's taxonomy id for a category. Facebook reads the same list, and a
 * child inherits its parent's id when it has none of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('google_category_id')->nullable()->after('sort_order');
            $table->boolean('in_feed')->default(true)->after('google_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['google_category_id', 'in_feed']);
        });
    }
};
