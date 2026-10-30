<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve the application's existing UTC literals in this same DB session.
        // A first TIMESTAMP can acquire an implicit ON UPDATE on older MySQL/MariaDB settings.
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dateTime('scheduled_for')->change();
            $table->dateTime('claimed_at')->nullable()->change();
            $table->dateTime('published_at')->nullable()->change();
            $table->dateTime('created_at')->nullable()->change();
            $table->dateTime('updated_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep DATETIME: restoring TIMESTAMP could silently reintroduce automatic rescheduling.
    }
};
