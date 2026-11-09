<?php

namespace App\Services\Feeds;

use App\Models\Product;
use XMLWriter;

class FacebookFeed extends ProductFeed
{
    protected ?PrimaryFeature $primaryFeature = null;

    public function generate(): string
    {
        $path = parent::generate();
        app(FacebookVideoFeed::class)->generate();

        return $path;
    }

    protected function query()
    {
        return parent::query()->with(($this->primaryFeature ??= app(PrimaryFeature::class))->eagerRelations());
    }

    protected function key(): string
    {
        return 'facebook';
    }

    protected function writeItem(XMLWriter $xml, Product $product): void
    {
        $name = trim((string) $product->name);
        [$price, $was] = $this->prices($product);
        // Compare the amounts actually exported: whole-lari rounding must not invent a discount.
        $was = $was !== null && round($was) > round($price) ? $was : null;
        $image = $this->image($product);

        if ($this->skip($product, $name, $price, $image)) {
            return;
        }

        $brand = $product->brand?->name ?: '—';
        $category = $product->category;
        $feature = ($this->primaryFeature ??= app(PrimaryFeature::class))->label($product);

        $xml->startElement('item');

        $xml->writeElement('g:id', (string) $product->id);
        $xml->writeElement('g:item_group_id', $this->groupId($product));
        $xml->writeElement('g:title', $this->title($brand, $name));
        $xml->writeElement('g:description', $this->description($product, $name));
        // This feed owns this feature field; empty output requests clearing stale overlays.
        $xml->writeElement('g:short_description', $feature);
        $xml->writeElement('g:link', $this->link($product));
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

        if ($google = $category?->google_category_id ?? $category?->parent?->google_category_id) {
            $xml->writeElement('g:google_product_category', (string) $google);
        }

        if ($category) {
            $xml->writeElement('g:product_type', $this->clean($category->name, 750));
        }

        $xml->writeElement('g:quantity_to_sell_on_facebook', (string) max(1, (int) $product->stock));
        $xml->writeElement('g:shipping_weight', $this->weight($product));

        $xml->writeElement('g:custom_label_0', $this->instalmentLabel($product, $price));
        // Label 1 is the user-selected main feature field; empty clears an unproven value.
        $xml->writeElement('g:custom_label_1', $feature);

        if ($parent = $category?->parent?->name ?? $category?->name) {
            $xml->writeElement('g:custom_label_2', $this->clean($parent, 100));
        }

        if ($supplier = $this->supplier($product)) {
            $xml->writeElement('g:custom_label_3', $this->clean($supplier, 100));
        }

        // An explicit empty value clears the overlay after a discount ends.
        $xml->writeElement('g:custom_label_4', $was !== null ? money($was) : '');

        $xml->endElement();
        $xml->flush();

        $this->written++;
    }

    protected function title(string $brand, string $name): string
    {
        return $this->clean(product_title($brand === '—' ? null : $brand, $name), 150);
    }

    protected function groupId(Product $product): string
    {
        return $product->variant_group
            ? 'group_'.$product->variant_group
            : 'product_default_group'.$product->id;
    }

    protected function link(Product $product): string
    {
        $url = route('product', $product->slug);

        return $this->config['utm']
            ? $url.'?'.$this->config['utm']
            : $url;
    }

    protected function instalmentLabel(Product $product, float $price): string
    {
        if ($product->is_preorder) {
            return 'preorder';
        }

        if ($price <= $this->config['instalment_from']) {
            return '';
        }

        $monthly = (int) ceil($price / $this->config['instalment_months']);

        return __('product.quick_monthly_from', ['amount' => money($monthly)]);
    }

    protected function supplier(Product $product): ?string
    {
        return $product->offers
            ->sortBy(fn ($offer) => $offer->supplier?->priority ?? PHP_INT_MAX)
            ->first()?->supplier?->name;
    }

    protected function weight(Product $product): string
    {
        return number_format((float) $product->weight, 2, '.', '').' kg';
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
