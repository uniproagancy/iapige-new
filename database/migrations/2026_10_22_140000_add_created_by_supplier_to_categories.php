<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'created_by_supplier_id')) {
                /* Set only on shelves a price list built for us, so they can be
                   swept away again without touching the shop's own. */
                $table->foreignId('created_by_supplier_id')->nullable()->after('parent_id')
                    ->constrained('suppliers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_supplier_id');
        });
    }
};
