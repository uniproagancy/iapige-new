<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_translations', function (Blueprint $table) {
            $table->id();
            $table->string('group', 64);
            $table->string('key');
            $table->string('locale', 10);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['group', 'key', 'locale']);
            $table->index(['locale', 'group']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_translations');
    }
};
