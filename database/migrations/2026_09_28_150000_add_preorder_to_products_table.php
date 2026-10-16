<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_preorder')->default(false)->after('stock');
            $table->date('release_date')->nullable()->after('is_preorder');

            $table->unsignedTinyInteger('prepay_percent')->nullable()->after('release_date');

            $table->index(['is_preorder', 'release_date']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_preorder')->default(false)->after('qty');
            $table->date('release_date')->nullable()->after('is_preorder');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['is_preorder', 'release_date']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_preorder', 'release_date']);
            $table->dropColumn(['is_preorder', 'release_date', 'prepay_percent']);
        });
    }
};
