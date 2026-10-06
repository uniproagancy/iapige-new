<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an attribute mapping nobody has looked at yet.
 *
 * Unknown attributes are created and mapped automatically, on purpose — a spec
 * we cannot show is a loss. But that left every new parameter looking exactly
 * like one an admin had already approved: the row carried an attribute_id, so
 * the "unmapped" filter never showed it, and a supplier introducing a
 * parameter we care about passed unnoticed.
 *
 * The flag is set when the importer invents the mapping and cleared the moment
 * a human assigns one, which is what turns the screen into a queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_attribute_map', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false)->after('attribute_id');
        });

        // everything mapped so far was mapped by the importer, not by a person
        Schema::getConnection()->table('supplier_attribute_map')
            ->whereNotNull('attribute_id')
            ->update(['is_auto' => true]);
    }

    public function down(): void
    {
        Schema::table('supplier_attribute_map', function (Blueprint $table) {
            $table->dropColumn('is_auto');
        });
    }
};
