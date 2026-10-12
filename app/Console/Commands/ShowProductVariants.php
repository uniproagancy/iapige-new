<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Catalog;
use Illuminate\Console\Command;

/**
 * Why a product's colour and memory switchers behave as they do.
 *
 * Three things have to line up before one works: the product and its siblings
 * must share a variant_group, the attribute must be marked as a variant, and
 * each option must belong to a different product. When an option is carried
 * by the product being viewed there is nowhere to send anybody, so the button
 * draws and does nothing — which looks exactly like a broken link.
 *
 * Searched by name, because a slug is the one thing nobody remembers.
 */
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

        if (! $product->variant_group) {
            $this->warn('  No variant_group, so this product has no siblings and no switchers.');

            return self::SUCCESS;
        }

        $siblings = Product::withTranslation()
            ->where('variant_group', $product->variant_group)
            ->orderBy('price')
            ->get();

        $this->line("  group <options=bold>{$product->variant_group}</> · {$siblings->count()} products in it");

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

        /*
         * The failure this command exists to name: every option belongs to the
         * product being looked at, so none of them can lead anywhere.
         */
        $dead = collect($switchers)->flatten(1)->every(fn (array $o) => empty($o['url']));

        if ($dead) {
            $this->line('');
            $this->warn('  Every option belongs to this product, so no button can lead anywhere.');
            $this->line('  Each colour or size should sit on the product that has it, one each.');
        }

        return self::SUCCESS;
    }
}
