<?php

use App\Support\Slug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign gets a readable address of its own.
 *
 * Its page was reached by `code` — unique and URL-safe, but an internal
 * handle: "week-deal" is what somebody typed into an admin field, not what a
 * shopper should see. Every other public thing in this shop carries a slug
 * per language, in its translations table, and a campaign now does the same.
 *
 * Added nullable, filled from whatever each row already has, and only then
 * made unique — a column added unique to a table with rows in it cannot be
 * filled afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_translations', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('locale');
        });

        $this->backfill();

        Schema::table('promotion_translations', function (Blueprint $table) {
            $table->unique(['locale', 'slug']);
        });
    }

    /**
     * The title makes the slug; the code is the fallback.
     *
     * A campaign with no title in some language still needs an address in it,
     * and two campaigns may well be called the same thing in a language
     * nobody has filled in yet — so a clash falls back to the row's own id,
     * which cannot repeat.
     */
    protected function backfill(): void
    {
        $taken = [];

        $rows = DB::table('promotion_translations')
            ->join('promotions', 'promotions.id', '=', 'promotion_translations.promotion_id')
            ->select('promotion_translations.id', 'promotion_translations.locale',
                'promotion_translations.title', 'promotions.code')
            ->get();

        foreach ($rows as $row) {
            $slug = Slug::make($row->title) ?: $row->code;
            $key = $row->locale.':'.$slug;

            if ($slug === '' || isset($taken[$key])) {
                $slug = $row->code.'-'.$row->id;
                $key = $row->locale.':'.$slug;
            }

            $taken[$key] = true;

            DB::table('promotion_translations')->where('id', $row->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('promotion_translations', function (Blueprint $table) {
            $table->dropUnique(['locale', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
