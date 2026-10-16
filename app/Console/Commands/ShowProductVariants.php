<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Catalog;
use Illuminate\Console\Command;

class ShowProductVariants extends Command
{
    protected $signature = 'product:variants {search : part of the name, the sku, or a slug}';

    protected $description = 'Show a product variant group and where each switcher leads';

    public function handle(): int
    {
        $term = trim($this->argument('search'));

        $product = Product::withTranslation()
            ->where('sku', $term)
            ->orWhereHas('translations', fn ($q) => $q
                ->where('slug', $term)
                ->orWhere('name', 'like', "%{$term}%"))
            ->first();

        if (! $product) {
            $this->error("Nothing matched [{$term}].");

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <options=bold>{$product->name}</>");
        $this->line("  sku {$product->sku} · id {$product->id}");
        $this->line('');

        $by = $product->variant_group ? 'variant_group' : 'sku';
        $value = $product->variant_group ?: $product->sku;

        $siblings = Product::withTranslation()
            ->where($by, $value)
            ->orderBy('price')
            ->get();

        $this->line("  grouped by <options=bold>{$by}</> = <options=bold>{$value}</> · {$siblings->count()} products");

        if ($siblings->count() < 2) {
            $this->line('');
            $this->warn('  Nothing to switch between: no other product shares that.');

            $this->nearby($product);

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'name', 'status', 'price'],
            $siblings->map(fn (Product $p) => [
                $p->id.($p->is($product) ? ' ←' : ''),
                $p->name,
                $p->status,
                $p->price,
            ]),
        );

        $switchers = Catalog::variantSwitchers($product);

        if (! $switchers) {
            $this->warn('  No switchers. The attributes carrying the options are not marked as variants.');

            return self::SUCCESS;
        }

        foreach ($switchers as $code => $options) {
            $this->line('');
            $this->line("  <options=bold>{$code}</>");

            $this->table(
                ['option', 'leads to', 'is this product'],
                collect($options)->map(fn (array $o) => [
                    $o['label'] ?? $o['code'],
                    $o['url'] ?: '— nowhere —',
                    ! empty($o['current']) ? 'yes' : '',
                ]),
            );
        }

        $dead = collect($switchers)->flatten(1)->every(fn (array $o) => empty($o['url']));

        if ($dead) {
            $this->line('');
            $this->warn('  Every option belongs to this product, so no button can lead anywhere.');
            $this->line('  Each colour or size should sit on the product that has it, one each.');
        }

        return self::SUCCESS;
    }

    protected function nearby(Product $product): void
    {

        $stem = trim(implode(' ', array_slice(preg_split('/\s+/', (string) $product->name), 0, 6)));

        $others = Product::withTranslation()
            ->whereKeyNot($product->id)
            ->whereHas('translations', fn ($q) => $q->where('name', 'like', $stem.'%'))
            ->orderBy('id')
            ->limit(15)
            ->get();

        if ($others->isEmpty()) {
            $this->line('  No other product is named like this one either.');

            return;
        }

        $this->line('');
        $this->line('  Named like it, and how each is grouped:');

        $this->table(
            ['id', 'name', 'variant_group', 'sku'],
            $others->map(fn (Product $p) => [
                $p->id,
                $p->name,
                $p->variant_group ?: '—',
                $p->sku,
            ])->prepend([
                $product->id.' ←',
                $product->name,
                $product->variant_group ?: '—',
                $product->sku,
            ]),
        );

        $this->line('  Same group or same sku on two rows is what makes them switchable.');
    }
}
