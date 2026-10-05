<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface strings ("კალათაში", "ფილტრი" …) editable from the admin panel.
 * Read through the normal translator: __('cart.add') → group "cart", key "add".
 * Rows override lang/*.php files, so a file can hold the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_translations', function (Blueprint $table) {
            $table->id();
            $table->string('group', 64);                    // cart, catalog … — "*" for JSON-style strings
            $table->string('key');                          // may be dotted: "filters.clear"
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
