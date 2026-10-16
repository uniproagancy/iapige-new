<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('short_name', 8)->nullable()->after('native_name');
            $table->string('regional', 16)->nullable()->after('short_name');
            $table->string('script', 8)->default('Latn')->after('regional');
        });
    }

    public function down(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn(['short_name', 'regional', 'script']);
        });
    }
};
