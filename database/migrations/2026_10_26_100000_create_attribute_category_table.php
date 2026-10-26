<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Which shelves an attribute belongs on.
 |
 | "Colour" is a thing one picks between on a phone and nonsense on a kettle,
 | but is_variant said it everywhere at once. An attribute listed here is
 | offered only under these categories; one listed nowhere carries on as
 | before and is offered under all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['attribute_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_category');
    }
};
