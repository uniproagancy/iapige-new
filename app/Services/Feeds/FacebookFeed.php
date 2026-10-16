<?php

namespace App\Services\Feeds;

use App\Models\Product;
use XMLWriter;

class FacebookFeed extends ProductFeed
{
    protected function key(): string
    {
        return 'facebook';
    }

    protected function writeItem(XMLWriter $xml, Product $product): void
    {
        $name = trim((string) $product->name);
        [$price, $was] = $this->prices($product);
        $image = $this->image($product);

        if ($this->skip($product, $name, $price, $image)) {
            return;
        }

        $brand = $product->brand?->name ?: '—';
        $category = $product->category;

        $xml->startElement('item');

        $xml->writeElement('g:id', (string) $product->id);
        $xml->writeElement('g:title', $this->clean(trim($brand.' '.$name), 150));
        $xml->writeElement('g:description', $this->description($product, $name));
        $xml->writeElement('g:link', route('product', $product->slug));
        $xml->writeElement('g:image_link', $image);

        foreach ($this->gallery($product) as $extra) {
            $xml->writeElement('g:additional_image_link', url($extra));
        }

        $xml->writeElement('g:availability', $this->availability($product));
        $xml->writeElement('g:condition', 'new');
        $xml->writeElement('g:brand', $this->clean($brand, 70));

        $xml->writeElement('g:price', $this->money($was ?: $price));

        if ($was) {
            $xml->writeElement('g:sale_price', $this->money($price));
        }

        if ($sku = $product->sku) {
            $xml->writeElement('g:mpn', $sku);
        }

        if ($google = $category?->google_category_id ?? $category?->parent?->google_category_id) {
            $xml->writeElement('g:google_product_category', (string) $google);
        }

        if ($category) {
            $xml->writeElement('g:product_type', $this->clean($this->productType($category), 750));
        }

        $xml->writeElement('g:quantity_to_sell_on_facebook', (string) max(1, (int) $product->stock));

        if ($product->is_preorder) {
            $xml->writeElement('g:custom_label_0', 'preorder');
        } elseif ($price > $this->config['instalment_from']) {
            $monthly = (int) ceil($price / $this->config['instalment_months']);
            $xml->writeElement('g:custom_label_0', "თვეში {$monthly}₾-დან");
        }

        if ($was && $was > $price) {
            $xml->writeElement('g:custom_label_1', '-'.(int) round((1 - $price / $was) * 100).'%');
        }

        if ($parent = $category?->parent?->name ?? $category?->name) {
            $xml->writeElement('g:custom_label_2', $this->clean($parent, 100));
        }

        $xml->writeElement('g:custom_label_3', $this->priceBand($price));

        $xml->endElement();
        $xml->flush();

        $this->written++;
    }

    protected function availability(Product $product): string
    {
        return match (true) {
            $product->stock > 0 => 'in stock',
            (bool) $product->is_preorder => 'available for order',
            default => 'out of stock',
        };
    }
}
