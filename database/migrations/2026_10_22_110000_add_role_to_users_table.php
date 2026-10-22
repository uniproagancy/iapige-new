<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 16)->nullable()->after('is_admin')->index();
            }
        });

        /* Everyone who could reach the admin until now keeps full access. */
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
        DB::table('users')->whereNull('role')->update(['role' => 'customer']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
