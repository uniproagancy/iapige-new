<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The home page's own slides.
 *
 * They were written into Support\Store, five of them, with their pictures
 * fetched from picsum.photos by number — placeholders standing in until there
 * was somewhere to put the real ones. Changing a date on the banner meant
 * editing PHP and deploying, which is why they still said "26—31 August" in
 * the middle of October.
 *
 * Scheduled like a campaign is, because a slide is one: "26—31 August" should
 * take itself down on the 1st rather than wait for somebody to notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slides', function (Blueprint $table) {
            $table->id();
            $table->string('image')->nullable();          // on the public disk
            $table->string('url')->nullable();            // where the button leads
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();   // null = already showing
            $table->timestamp('ends_at')->nullable();     // null = no end
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index('sort_order');
        });

        Schema::create('slide_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slide_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);

            $table->string('dates')->nullable();   // the small line above: "26—31 August"
            $table->string('title')->nullable();   // two lines, split on a newline
            $table->string('sub')->nullable();
            $table->string('cta')->nullable();     // the button's label

            $table->unique(['slide_id', 'locale']);
            $table->foreign('locale')->references('code')->on('languages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_translations');
        Schema::dropIfExists('slides');
    }
};
