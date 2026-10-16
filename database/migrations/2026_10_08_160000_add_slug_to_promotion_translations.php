<?php

use App\Support\Slug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
