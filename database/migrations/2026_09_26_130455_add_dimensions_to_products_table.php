<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('weight')->nullable()->after('stock');       // grams
            $table->unsignedSmallInteger('length')->nullable()->after('weight');  // mm
            $table->unsignedSmallInteger('width')->nullable()->after('length');
            $table->unsignedSmallInteger('height')->nullable()->after('width');
            $table->boolean('is_bulky')->default(false)->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['weight', 'length', 'width', 'height', 'is_bulky']);
        });
    }
};
