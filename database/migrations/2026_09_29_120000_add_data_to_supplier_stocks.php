<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The whole spreadsheet row, kept beside the quantity.
 *
 * Some suppliers send far more than stock in their file — Midea's carries the
 * category, description, both prices and the warranty. Keeping the row means
 * the import does not have to re-read the site for what the file already said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_stocks', function (Blueprint $table) {
            $table->json('data')->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_stocks', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};
