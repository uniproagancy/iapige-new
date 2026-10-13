<?php

namespace App\Services\Feeds;

use App\Models\Product;
use XMLWriter;

/**
 * The Google Merchant Center feed.
 *
 * Nearly the same document as the Facebook one — both read RSS with Google's
 * own namespace — so everything shared lives in ProductFeed and only what
 * Google insists on differently is here:
 *
 *   - availability is written with underscores, "in_stock", and a pre-order
 *     is "preorder" rather than "available for order";
 *   - an item needs something to identify it by. Brand and MPN together will
 *     do, and a barcode is better still, but an item with neither must say so
 *     outright with identifier_exists — left out, Google rejects it;
 *   - variants are grouped by item_group_id, so a phone's colours compete as
 *     one product rather than against each other;
 *   - nothing Facebook-specific, which is a field Google would warn about.
 */
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
        $xml->writeElement('g:title', $this->clean(trim(($brand ?: '').' '.$name), 150));
        $xml->writeElement('g:description', $this->description($product, $name));
        $xml->writeElement('g:link', route('product', $product->slug));
        $xml->writeElement('g:image_link', $image);

        foreach ($this->gallery($product) as $extra) {
            $xml->writeElement('g:additional_image_link', url($extra));
        }

        $xml->writeElement('g:availability', $this->availability($product));
        $xml->writeElement('g:condition', 'new');

        /*
         * The price Google checks against the landing page is the one a
         * shopper pays, so a campaign price goes in sale_price and the shelf
         * price stays in price. A mismatch here is how items get disapproved.
         */
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

        // a phone's colours are one product to Google, not several competing ones
        if ($product->variant_group) {
            $xml->writeElement('g:item_group_id', $this->clean($product->variant_group, 50));
        }

        $this->writeLabels($xml, $product, $price, $was);

        $xml->endElement();
        $xml->flush();

        $this->written++;
    }

    /* ------------------------------------------------------------------ fields */

    /**
     * Brand, barcode and part number.
     *
     * Google wants two of the three for most categories and will not take an
     * item that quietly has none — identifier_exists is how an item says it
     * has nothing to be identified by, and without it such an item is simply
     * rejected.
     *
     * The sku is the supplier's barcode wherever one was given, which is a
     * GTIN when it is eight to fourteen digits. Anything else — the
     * "ZOOM-54321" the importer makes up for a row with no barcode — is a
     * part number at best.
     */
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

    /**
     * Google's own words.
     *
     * "in stock" with a space is what Facebook reads; Google wants
     * "in_stock", and calls a pre-order "preorder" rather than "available for
     * order". Same three states, three different spellings.
     */
    protected function availability(Product $product): string
    {
        return match (true) {
            (bool) $product->is_preorder => 'preorder',
            $product->stock > 0 => 'in_stock',
            default => 'out_of_stock',
        };
    }

    /** What a campaign can actually be segmented on. */
    protected function writeLabels(XMLWriter $xml, Product $product, float $price, ?float $was): void
    {
        if ($product->is_preorder) {
            $xml->writeElement('g:custom_label_0', 'preorder');
        } elseif ($price > $this->config['instalment_from']) {
            $monthly = (int) ceil($price / $this->config['instalment_months']);
            $xml->writeElement('g:custom_label_0', "თვეში {$monthly}₾-დან");
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
