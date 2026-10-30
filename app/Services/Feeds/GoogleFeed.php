<?php

namespace App\Services\Feeds;

use App\Models\Product;
use XMLWriter;

class GoogleFeed extends ProductFeed
{
    protected function key(): string
    {
        return 'google';
    }

    protected function writeItem(XMLWriter $xml, Product $product): void
    {
        $name = trim((string) $product->name);
        [$price, $was] = $this->prices($product);
        $image = $this->image($product);

        if ($this->skip($product, $name, $price, $image)) {
            return;
        }

        $brand = $product->brand?->name;
        $category = $product->category;

        $xml->startElement('item');

        $xml->writeElement('g:id', (string) $product->id);
        $xml->writeElement('g:title', $this->clean(product_title($brand, $name), 150));
        $xml->writeElement('g:description', $this->description($product, $name));
        $xml->writeElement('g:link', route('product', $product->slug));
        $xml->writeElement('g:image_link', $image);

        foreach ($this->gallery($product) as $extra) {
            $xml->writeElement('g:additional_image_link', url($extra));
        }

        $xml->writeElement('g:availability', $this->availability($product));
        $xml->writeElement('g:condition', 'new');

        $xml->writeElement('g:price', $this->money($was ?: $price));

        if ($was) {
            $xml->writeElement('g:sale_price', $this->money($price));
        }

        $this->writeIdentity($xml, $product, $brand);

        if ($google = $category?->google_category_id ?? $category?->parent?->google_category_id) {
            $xml->writeElement('g:google_product_category', (string) $google);
        }

        if ($category) {
            $xml->writeElement('g:product_type', $this->clean($this->productType($category), 750));
        }

        if ($product->variant_group) {
            $xml->writeElement('g:item_group_id', $this->clean($product->variant_group, 50));
        }

        $this->writeLabels($xml, $product, $price, $was);

        $xml->endElement();
        $xml->flush();

        $this->written++;
    }

    protected function writeIdentity(XMLWriter $xml, Product $product, ?string $brand): void
    {
        if ($brand) {
            $xml->writeElement('g:brand', $this->clean($brand, 70));
        }

        $sku = trim((string) $product->sku);
        $isGtin = preg_match('/^\d{8}$|^\d{12,14}$/', $sku) === 1;

        if ($isGtin) {
            $xml->writeElement('g:gtin', $sku);
        } elseif ($sku !== '') {
            $xml->writeElement('g:mpn', $sku);
        }

        if (! $brand || $sku === '') {
            $xml->writeElement('g:identifier_exists', 'no');
        }
    }

    protected function availability(Product $product): string
    {
        return match (true) {
            (bool) $product->is_preorder => 'preorder',
            $product->stock > 0 => 'in_stock',
            default => 'out_of_stock',
        };
    }

    protected function writeLabels(XMLWriter $xml, Product $product, float $price, ?float $was): void
    {
        if ($product->is_preorder) {
            $xml->writeElement('g:custom_label_0', 'preorder');
        } elseif ($price > $this->config['instalment_from']) {
            $monthly = (int) ceil($price / $this->config['instalment_months']);
            $xml->writeElement('g:custom_label_0', __('product.quick_monthly_from', ['amount' => money($monthly)]));
        }

        if ($was && $was > $price) {
            $xml->writeElement('g:custom_label_1', '-'.(int) round((1 - $price / $was) * 100).'%');
        }

        if ($parent = $product->category?->parent?->name ?? $product->category?->name) {
            $xml->writeElement('g:custom_label_2', $this->clean($parent, 100));
        }

        $xml->writeElement('g:custom_label_3', $this->priceBand($price));
    }
}
