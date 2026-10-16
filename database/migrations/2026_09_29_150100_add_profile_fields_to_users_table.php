<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 32)->nullable()->after('email');
            }

            $table->boolean('accepts_marketing')->default(false)->after('phone');
            $table->string('locale', 10)->nullable()->after('accepts_marketing');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['accepts_marketing', 'locale']);
        });
    }
};
