<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AttributeDoctor extends Command
{
    protected $signature = 'attributes:doctor
        {--limit=25 : how many rows of each kind to print}';

    protected $description = 'Find attributes that share a name, and supplier keys mapped onto the wrong one';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->twins($limit);
        $this->doubledOnProducts($limit);
        $this->suspiciousMappings($limit);

        return self::SUCCESS;
    }

    protected function twins(int $limit): void
    {
        $locale = app()->getLocale();

        $names = DB::table('attribute_translations')
            ->where('locale', $locale)
            ->select('name', DB::raw('count(*) as n'), DB::raw('group_concat(attribute_id) as ids'))
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('n')
            ->limit($limit)
            ->get();

        $this->line('');
        $this->info('Attributes that show the same name');

        if ($names->isEmpty()) {
            $this->line('  none — every attribute name is unique');

            return;
        }

        $rows = [];

        foreach ($names as $row) {
            $ids = explode(',', (string) $row->ids);

            $codes = Attribute::whereIn('id', $ids)->pluck('code', 'id');
            $usage = DB::table('product_specs')
                ->whereIn('attribute_id', $ids)
                ->select('attribute_id', DB::raw('count(*) as n'))
                ->groupBy('attribute_id')
                ->pluck('n', 'attribute_id');

            $detail = collect($ids)
                ->map(fn ($id) => ($codes[$id] ?? '?').' ('.($usage[$id] ?? 0).')')
                ->implode('  ·  ');

            $rows[] = [$row->name, $row->n, $detail];
        }

        $this->table(['name', 'how many', 'codes (products using each)'], $rows);
        $this->line('  Keep the code with the most products and point the others at it.');
    }

    protected function doubledOnProducts(int $limit): void
    {
        $locale = app()->getLocale();

        $rows = DB::table('product_specs as ps')
            ->join('attribute_translations as at', function ($join) use ($locale) {
                $join->on('at.attribute_id', '=', 'ps.attribute_id')->where('at.locale', $locale);
            })
            ->select('ps.product_id', 'at.name', DB::raw('count(*) as n'))
            ->groupBy('ps.product_id', 'at.name')
            ->havingRaw('count(*) > 1')
            ->limit($limit)
            ->get();

        $this->line('');
        $this->info('Products showing the same row twice');

        if ($rows->isEmpty()) {
            $this->line('  none');

            return;
        }

        $this->table(
            ['product', 'row shown twice', 'times'],
            $rows->map(fn ($r) => [$r->product_id, $r->name, $r->n])->all(),
        );

        $total = DB::table('product_specs as ps')
            ->join('attribute_translations as at', function ($join) use ($locale) {
                $join->on('at.attribute_id', '=', 'ps.attribute_id')->where('at.locale', $locale);
            })
            ->select('ps.product_id', 'at.name')
            ->groupBy('ps.product_id', 'at.name')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $this->line("  {$total} product/row pairs in all — merging the twins above fixes them.");
    }

    protected function suspiciousMappings(int $limit): void
    {
        $locale = app()->getLocale();

        $rows = DB::table('supplier_attribute_map as m')
            ->join('attribute_translations as at', function ($join) use ($locale) {
                $join->on('at.attribute_id', '=', 'm.attribute_id')->where('at.locale', $locale);
            })
            ->join('suppliers as s', 's.id', '=', 'm.supplier_id')
            ->select('s.name as supplier', 'm.external_name', 'at.name as mapped_to', 'm.attribute_id')
            ->get();

        $odd = $rows->filter(function ($row) {
            $from = mb_strtolower(trim($row->external_name));
            $to = mb_strtolower(trim($row->mapped_to));

            if ($from === $to) {
                return false;
            }

            return ! str_contains($to, $from) && ! str_contains($from, $to);
        });

        $this->line('');
        $this->info('Supplier keys mapped to a differently named attribute');

        if ($odd->isEmpty()) {
            $this->line('  none');

            return;
        }

        $this->table(
            ['supplier', 'their key', 'shown as', 'attribute id'],
            $odd->take($limit)->map(fn ($r) => [$r->supplier, $r->external_name, $r->mapped_to, $r->attribute_id])->all(),
        );

        $this->line('  '.$odd->count().' in all. Most are fine (a translation), but a wrong one hides here.');
        $this->line('  Fix them in the admin: Import → Mapping → Attributes.');
    }
}
